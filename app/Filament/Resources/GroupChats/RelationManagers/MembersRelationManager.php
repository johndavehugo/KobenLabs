<?php

namespace App\Filament\Resources\GroupChats\RelationManagers;

use App\Enums\GroupRole;
use App\Models\GroupChat;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Who is in a group, and as what.
 *
 * Membership is the only access control on a transcript, so this is where it is
 * administered. Every write goes through `GroupChat::join()` / `leave()` so the
 * pivot's role and joined_at are set in one place, rather than through a
 * bespoke attach form that would have to duplicate them.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('username')
            ->columns([
                TextColumn::make('username')->label('Username')->searchable()->sortable(),
                TextColumn::make('full_name')->label('Name'),
                TextColumn::make('group_role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => GroupRole::tryFrom((string) $state)?->label() ?? '—'),
                TextColumn::make('pivot.joined_at')->label('Joined')->dateTime(),
            ])
            ->headerActions([
                $this->addMemberAction(),
            ])
            ->recordActions([
                $this->toggleAdminAction(),
                DetachAction::make()->label('Remove'),
            ])
            ->defaultSort('username');
    }

    private function addMemberAction(): Action
    {
        return Action::make('addMember')
            ->label('Add member')
            ->icon('heroicon-o-user-plus')
            ->modalHeading('Add a member')
            ->schema([
                Select::make('user_id')
                    ->label('User')
                    ->options(fn (): array => $this->addableUserOptions())
                    ->searchable()
                    ->required(),

                Select::make('role')
                    ->label('Role')
                    ->options([
                        GroupRole::Member->value => GroupRole::Member->label(),
                        GroupRole::Admin->value => 'Group admin — may remove other members\' messages',
                    ])
                    ->default(GroupRole::Member->value)
                    ->required(),
            ])
            ->action(function (array $data) {
                $group = $this->group();

                if ($group === null) {
                    return;
                }

                $group->join(
                    User::query()->findOrFail((int) $data['user_id']),
                    GroupRole::from((string) $data['role']),
                );

                Notification::make()->success()->title('Member added')->send();
            });
    }

    /**
     * @return array<int, string>
     */
    private function addableUserOptions(): array
    {
        $group = $this->group();

        if ($group === null) {
            return [];
        }

        $memberIds = $group->members()->pluck('users.id');

        return User::query()
            ->whereNotIn('id', $memberIds)
            ->orderBy('username')
            ->pluck('username', 'id')
            ->all();
    }

    private function toggleAdminAction(): Action
    {
        return Action::make('toggleAdmin')
            ->label(fn (User $record): string => $this->roleOf($record) === GroupRole::Admin
                ? 'Revoke admin'
                : 'Make group admin')
            ->requiresConfirmation()
            ->action(function (User $record) {
                $group = $this->group();

                if ($group === null) {
                    return;
                }

                $promoting = $this->roleOf($record) !== GroupRole::Admin;

                $group->join($record, $promoting ? GroupRole::Admin : GroupRole::Member);

                Notification::make()
                    ->success()
                    ->title($promoting ? 'Group admin granted' : 'Group admin revoked')
                    ->send();
            });
    }

    private function roleOf(User $user): ?GroupRole
    {
        return $this->group()?->roleFor($user);
    }

    private function group(): ?GroupChat
    {
        $group = $this->getOwnerRecord();

        return $group instanceof GroupChat ? $group : null;
    }
}
