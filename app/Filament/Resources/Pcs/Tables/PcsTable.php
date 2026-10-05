<?php

namespace App\Filament\Resources\Pcs\Tables;

use App\Enums\PcStatus;
use App\Events\PcStatusUpdated;
use App\Models\Pc;
use App\Services\PcPresenceService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
                Action::make('changeStatus')
                    ->label('Change status')
                    ->icon('heroicon-m-signal')
                    ->visible(fn (): bool => auth()->user()?->can('changeStatus', new Pc))
                    // The manual override the legacy PC view offered. Preserved,
                    // but now gated by PcPolicy::changeStatus — the legacy
                    // controller had no role check at all on this path.
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
                    ->successNotificationTitle('Status updated'),

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
}
