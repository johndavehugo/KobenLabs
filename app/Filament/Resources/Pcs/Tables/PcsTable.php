<?php

namespace App\Filament\Resources\Pcs\Tables;

use App\Enums\LabClientState;
use App\Enums\PcStatus;
use App\Events\PcStatusUpdated;
use App\Models\Pc;
use App\Services\PcClientEnrolment;
use App\Services\PcPresenceService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PcsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Pc $record): ?string => $record->activeSessions->isEmpty()
                        ? null
                        : 'In use by '.$record->activeSessions->first()->user->full_name),

                TextColumn::make('ip_address')
                    ->label('IP address')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PcStatus $state): string => $state->color()),

                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable()
                    ->since()
                    ->description(fn (Pc $record): ?string => $record->isStale((int) config('lab.pcs.stale_after_minutes'))
                        ? 'Idle past the '
                            .config('lab.pcs.stale_after_minutes')
                            .' minute threshold — will be collected on the next sweep'
                        : null),

                TextColumn::make('client_state')
                    ->label('Lab Client')
                    ->badge()
                    // Not a database column — derived from the pc_clients relation so
                    // "reporting" reflects a frame that is actually current, not merely
                    // that a row exists.
                    //
                    // formatStateUsing, not state(): client_state is not a cast attribute
                    // on the model, so Filament has no enum to resolve and renders the
                    // raw value — "not_enrolled" rather than "Not enrolled". The
                    // neighbouring `status` column works only because Pc::$status is cast
                    // to PcStatus, which is a different code path entirely.
                    ->state(fn (Pc $record): LabClientState => self::clientState($record))
                    ->formatStateUsing(fn (LabClientState $state): string => $state->label())
                    ->color(fn (Pc $record): string => self::clientState($record)->color()),

                TextColumn::make('mac_address')
                    ->label('MAC address')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PcStatus::class),
            ])
            ->recordActions([
                self::enrolClientAction(),
                self::revokeClientAction(),
                self::changeStatusAction(),

                EditAction::make(),
            ])
            ->toolbarActions([
                Action::make('collectStale')
                    ->label('Collect idle PCs')
                    ->icon('heroicon-m-arrow-path')
                    // Was `GET /api/pcs/cleanup-stale`, fired on every render of
                    // the PC view. Now a real command on a schedule, with this
                    // action as the manual equivalent.
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $count = app(PcPresenceService::class)->markStalePcsOffline();

                        Notification::make()
                            ->title($count === 1 ? '1 PC marked offline' : "{$count} PCs marked offline")
                            ->success()
                            ->send();
                    }),

                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    // -----------------------------------------------------------------
    // Lab Client actions
    //
    // Issue and withdraw the credential a lab PC uses to report its screen.
    //
    // The button hands an administrator a one-time code and nothing else. It does not
    // try to push the installer onto the machine: that needs administrator rights on
    // that PC, so it would still be a manual click there, and a half-finished remote
    // install is harder to reason about than one an administrator runs themselves.
    // -----------------------------------------------------------------

    /**
     * Mint a credential and show it once.
     *
     * The plaintext is displayed in a copyable field and never stored, so it cannot be
     * shown again. If it is lost, re-enrolling issues a fresh one — which is also why
     * re-enrolling an already-enrolled PC warns first: the old code stops working, and
     * any machine still running it drops off the grid until it is updated.
     */
    public static function enrolClientAction(): Action
    {
        return Action::make('enrolClient')
            ->label('Enrol client')
            // computer-desktop, not monitor: heroicons has no `monitor` at all, and
            // Blade Icons throws on an unknown name rather than rendering a gap — which
            // takes the whole PC list page down with a 500 rather than one missing icon.
            ->icon('heroicon-o-computer-desktop')
            ->color('primary')
            ->visible(fn (Pc $record): bool => auth()->user()?->can('manageClient', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (Pc $record): string => 'Enrol the Lab Client for '.$record->name)
            ->modalDescription(fn (Pc $record): string => $record->client === null
                ? 'Generates a one-time code for this machine. Paste it into the Lab Client installer on that PC.'
                : 'This replaces the existing code. The machine will stop reporting until the new code is installed there.')
            ->modalWidth('xl')
            // Minted here rather than in ->action(): the modal has to show the code
            // when it opens, not after a confirm the modal itself is asking for.
            // fillForm() is the mount hook, so the token is generated exactly once per
            // opening and the modal renders with it already populated.
            ->fillForm(function (Pc $record): array {
                $result = app(PcClientEnrolment::class)->enrol($record);

                return ['token' => $result['token']];
            })
            ->schema([
                TextInput::make('token')
                    ->label('Code')
                    // Read-only rather than disabled: a disabled input is skipped by
                    // screen readers, and this value has to be selectable and readable
                    // by assistive tech to be of any use.
                    ->readOnly()
                    ->copyable()
                    ->extraInputAttributes([
                        'autocomplete' => 'off',
                        'autocapitalize' => 'off',
                        'autocorrect' => 'off',
                        'spellcheck' => 'false',
                        'class' => 'font-mono',
                    ])
                    ->dehydrateStateUsing(fn (string $state): array => []),
            ]);
    }

    /**
     * Withdraw a credential. Deletes the stored frame as well, so a machine nobody is
     * permitted to watch does not leave its last screenshot behind on disk.
     */
    public static function revokeClientAction(): Action
    {
        return Action::make('revokeClient')
            ->label('Revoke client')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (Pc $record): bool => auth()->user()?->can('manageClient', $record) ?? false)
            // Hidden rather than disabled: a disabled button on every unenrolled row
            // is noise, and the label already explains the current state.
            ->hidden(fn (Pc $record): bool => $record->client === null || ! $record->client->isActive())
            ->requiresConfirmation()
            ->modalHeading('Revoke the Lab Client?')
            ->modalDescription('The machine will stop reporting immediately, its code will stop working, and its last stored frame will be deleted. The client can be re-enrolled at any time.')
            ->modalSubmitActionLabel('Revoke')
            ->action(function (Pc $record): void {
                app(PcClientEnrolment::class)->revoke($record);
            })
            ->successNotificationTitle('Lab Client revoked');
    }

    /**
     * The manual occupancy override the legacy PC view offered.
     *
     * Preserved, and now gated by PcPolicy::changeStatus — the legacy controller had
     * no role check at all on this path.
     */
    public static function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Change status')
            ->icon('heroicon-m-signal')
            ->visible(fn (): bool => auth()->user()?->can('changeStatus', new Pc))
            ->schema([
                Select::make('status')
                    ->options(PcStatus::class)
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data, Pc $record): void {
                // Select::options(UserEnum::class) casts the submitted
                // value, so this is already a PcStatus instance.
                $status = $data['status'];

                $record->forceFill(['status' => $status])->save();

                if ($status === PcStatus::Online) {
                    $record->touchLastSeen();
                }

                PcStatusUpdated::dispatch($record->id, $record->name, $status);
            })
            ->successNotificationTitle('Status updated');
    }

    /**
     * How a PC's Lab Client is doing, for the table badge.
     *
     * Derived rather than a column, so "reporting" means a frame is genuinely current
     * rather than merely that a pc_clients row exists. The distinction matters: a row
     * that exists but stopped uploading an hour ago is a silent machine, and showing
     * it as active would be the same lie as serving a frozen image on the grid.
     */
    public static function clientState(Pc $record): LabClientState
    {
        $client = $record->client;

        if ($client === null) {
            return LabClientState::NotEnrolled;
        }

        if (! $client->isActive()) {
            return LabClientState::Revoked;
        }

        // Never-checked-in is tested before isSilent(), and it has to be. isSilent()
        // returns true for a null last_seen_at, so checking it first reports every
        // machine that has never reached the server as merely "Silent" — and those
        // need opposite fixes: never-seen means the code or server URL is wrong, silent
        // means the machine needs looking at. LabClientStatus is right for the same
        // reason and reads in the same order.
        if ($client->last_seen_at === null) {
            return LabClientState::NeverSeen;
        }

        return $client->isSilent() ? LabClientState::Silent : LabClientState::Reporting;
    }
}
