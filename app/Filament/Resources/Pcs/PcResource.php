<?php

namespace App\Filament\Resources\Pcs;

use App\Filament\Resources\Pcs\Pages\CreatePc;
use App\Filament\Resources\Pcs\Pages\EditPc;
use App\Filament\Resources\Pcs\Pages\ListPcs;
use App\Filament\Resources\Pcs\Schemas\PcForm;
use App\Filament\Resources\Pcs\Tables\PcsTable;
use App\Models\Pc;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PcResource extends Resource
{
    protected static ?string $model = Pc::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|BackedEnum|UnitEnum|null $navigationGroup = 'Laboratory';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'PC';

    protected static ?string $pluralModelLabel = 'PCs';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PcForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PcsTable::configure($table);
    }

    /**
     * The occupancy line under each PC name needs the occupant, so eager load
     * it rather than issuing a query per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['activeSessions.user']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPcs::route('/'),
            'create' => CreatePc::route('/create'),
            'edit' => EditPc::route('/{record}/edit'),
        ];
    }
}
