<?php

namespace App\Filament\Resources\StoredFiles;

use App\Filament\Resources\StoredFiles\Pages\ListStoredFiles;
use App\Filament\Resources\StoredFiles\Pages\ViewStoredFile;
use App\Filament\Resources\StoredFiles\Schemas\StoredFileInfolist;
use App\Filament\Resources\StoredFiles\Tables\StoredFilesTable;
use App\Models\StoredFile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Staff view of every file in the lab.
 *
 * The legacy app had no equivalent. Teachers browsed drives through a
 * `?user_id=` query parameter, which meant "support a student" was an
 * unaudited URL. Keeping the same reach is deliberate — staff need it to find a
 * student's work — but it is now a policy question (`StoredFilePolicy`) rather
 * than something a query string decides.
 */
class StoredFileResource extends Resource
{
    protected static ?string $model = StoredFile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|BackedEnum|UnitEnum|null $navigationGroup = 'Lab';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'file';

    protected static ?string $pluralModelLabel = 'files';

    public static function infolist(Schema $schema): Schema
    {
        return StoredFileInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoredFilesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user'])
            ->select(['stored_files.*']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    /**
     * List and view only. No create and no edit routes exist, so the permissive
     * `StoredFilePolicy::create` cannot be reached through the UI.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStoredFiles::route('/'),
            'view' => ViewStoredFile::route('/{record}'),
        ];
    }
}
