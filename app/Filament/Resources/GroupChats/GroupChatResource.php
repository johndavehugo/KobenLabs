<?php

namespace App\Filament\Resources\GroupChats;

use App\Filament\Resources\GroupChats\Pages\CreateGroupChat;
use App\Filament\Resources\GroupChats\Pages\EditGroupChat;
use App\Filament\Resources\GroupChats\Pages\ListGroupChats;
use App\Filament\Resources\GroupChats\Pages\ViewGroupChat;
use App\Filament\Resources\GroupChats\RelationManagers\MembersRelationManager;
use App\Filament\Resources\GroupChats\Schemas\GroupChatForm;
use App\Filament\Resources\GroupChats\Schemas\GroupChatInfolist;
use App\Filament\Resources\GroupChats\Tables\GroupChatsTable;
use App\Models\GroupChat;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Administration of group chats.
 *
 * Membership is the access control for a transcript, so creating a group here is
 * what starts one: the join code it generates is the only way in for a student,
 * and `GroupChatPolicy::join` grants it to anyone who has it while the group is
 * active.
 *
 * Students reach their own transcripts through the ChatRoom page on the student
 * panel, never through this resource.
 */
class GroupChatResource extends Resource
{
    protected static ?string $model = GroupChat::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|BackedEnum|UnitEnum|null $navigationGroup = 'Lab';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $pluralModelLabel = 'group chats';

    public static function form(Schema $schema): Schema
    {
        return GroupChatForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GroupChatInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GroupChatsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['creator'])
            ->withCount(['members', 'chatMessages'])
            ->select(['group_chats.*']);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroupChats::route('/'),
            'create' => CreateGroupChat::route('/create'),
            'view' => ViewGroupChat::route('/{record}'),
            'edit' => EditGroupChat::route('/{record}/edit'),
        ];
    }
}
