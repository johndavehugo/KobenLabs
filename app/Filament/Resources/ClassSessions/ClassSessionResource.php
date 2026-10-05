<?php

namespace App\Filament\Resources\ClassSessions;

use App\Filament\Resources\ClassSessions\Pages\CreateClassSession;
use App\Filament\Resources\ClassSessions\Pages\ListClassSessions;
use App\Filament\Resources\ClassSessions\Pages\ViewClassSession;
use App\Filament\Resources\ClassSessions\RelationManagers\AttendancesRelationManager;
use App\Filament\Resources\ClassSessions\Schemas\ClassSessionInfolist;
use App\Filament\Resources\ClassSessions\Tables\ClassSessionsTable;
use App\Models\ClassSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ClassSessionResource extends Resource
{
    protected static ?string $model = ClassSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|BackedEnum|UnitEnum|null $navigationGroup = 'Academic';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'id';

    /**
     * A class session is never hand-edited — it is started from a schedule and
     * ended. Only the read-only infolist is registered, so there is no form and
     * no Edit page.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClassSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClassSessionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['schedule', 'schedule.teacher', 'schedule.yearSection', 'startedBy'])
            ->select(['class_sessions.*']);
    }

    public static function getRelations(): array
    {
        return [
            AttendancesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClassSessions::route('/'),
            // Create is a custom page: picking a schedule and pressing "Start
            // class" runs ClassSessionService::start(), which fires the
            // broadcast. A generated create form would write the row directly and
            // skip both the single-active-session rule and the event.
            'create' => CreateClassSession::route('/start'),
            'view' => ViewClassSession::route('/{record}'),
        ];
    }
}
