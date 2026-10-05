<?php

namespace App\Filament\Resources\YearSections;

use App\Filament\Resources\YearSections\Pages\CreateYearSection;
use App\Filament\Resources\YearSections\Pages\EditYearSection;
use App\Filament\Resources\YearSections\Pages\ListYearSections;
use App\Filament\Resources\YearSections\Pages\ViewYearSection;
use App\Filament\Resources\YearSections\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\YearSections\Schemas\YearSectionForm;
use App\Filament\Resources\YearSections\Schemas\YearSectionInfolist;
use App\Filament\Resources\YearSections\Tables\YearSectionsTable;
use App\Models\YearSection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class YearSectionResource extends Resource
{
    protected static ?string $model = YearSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|BackedEnum|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'year and section';

    protected static ?string $pluralModelLabel = 'years and sections';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return YearSectionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return YearSectionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return YearSectionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StudentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListYearSections::route('/'),
            'create' => CreateYearSection::route('/create'),
            'view' => ViewYearSection::route('/{record}'),
            'edit' => EditYearSection::route('/{record}/edit'),
        ];
    }
}
