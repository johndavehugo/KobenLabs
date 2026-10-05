<?php

namespace App\Filament\Resources\ClassSessions\RelationManagers;

use App\Enums\UserStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The roster for one class session.
 *
 * Deliberately read-only. Attendance is written by joining a class and backed by
 * a unique index on (class_session_id, user_id); AttendancePolicy returns false
 * for both update and delete even for administrators, because a mark that can be
 * changed after the fact is not evidence of anything.
 *
 * The legacy equivalent was a modal fed by
 * `GET /api/class-sessions/{id}/attendance`, which had no role check at all — any
 * authenticated user could read any roster.
 */
class AttendancesRelationManager extends RelationManager
{
    protected static string $relationship = 'attendance';

    protected static ?string $title = 'Attendance';

    /**
     * Rendered eagerly. The roster is the reason to open this page, and a lazy
     * manager leaves a placeholder until the tab is clicked.
     */
    protected static bool $isLazy = false;

    /**
     * The roster is evidence, not a queue: Filament denies create, edit and delete
     * actions on a read-only relation manager, which is stronger and less fragile
     * than an empty action array.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('joined_at')
            ->defaultSort('joined_at')
            ->columns([
                TextColumn::make('user.full_name')
                    ->label('Student')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name'])
                    ->weight('bold'),

                TextColumn::make('user.student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('user.yearSection.label')
                    ->label('Year and section')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('user.status')
                    ->badge(),

                TextColumn::make('joined_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('user_status')
                    ->label('Student status')
                    ->options(UserStatus::class)
                    // Scoped with an explicit whereHas rather than
                    // ->relationship(), which builds its option labels from the
                    // related model's status column and cannot cope with an enum.
                    ->query(function (Builder $query, array $data): void {
                        $status = $data['value'] ?? null;

                        if (blank($status)) {
                            return;
                        }

                        $query->whereHas(
                            'user',
                            fn (Builder $userQuery) => $userQuery->where('status', $status),
                        );
                    }),
            ])
            // No header or record actions are registered: a read-only relation
            // manager already denies them.
            ->headerActions([])
            ->recordActions([]);
    }

    /**
     * The roster is more sensitive than the session itself — it lists who was
     * present. Visible to staff and to the teacher who started the session.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAttendance', $ownerRecord) ?? false;
    }
}
