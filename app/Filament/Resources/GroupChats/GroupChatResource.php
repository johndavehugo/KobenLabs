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

    /**
     * Hidden from the sidebar.
     *
     * This resource and the Group Chat page were both registered under "Lab", so the
     * navigation carried "Group chats" and "Group Chat" one line apart: one a CRUD list,
     * one the actual conversation, both leading to the same feature by different routes.
     * ChatRoom is the one to keep - it is where groups are read, written, joined and left.
     *
     * The routes stay. Renaming a group, editing its join code and managing members all
     * live here and are reached from the chat itself, so nothing becomes unreachable.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static string|BackedEnum|UnitEnum|null $navigationGroup = 'Laboratory';

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

    /**
     * Only the groups this user has a claim on.
     *
     * There was no filter here at all, and GroupChatPolicy::viewAny() returns true, so anyone
     * who could reach /admin/group-chats was shown every group in the lab - including its
     * join code, which is the credential a student redeems to get in. Hiding the resource
     * from the sidebar narrowed the accident but not the hole: the URL still worked, and a
     * list filtered only by presentation is not a control.
     *
     * The rule matches the one the chat itself uses: a staff member's groups are the ones they
     * created, a student's are the ones they joined. So a group appears in the admin list if
     * and only if it also appears in that person's chat - which is the property that makes the
     * two screens agree rather than merely look similar.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['creator'])
            ->withCount(['members', 'chatMessages'])
            ->select(['group_chats.*'])
            ->when(
                $user?->canManageLab(),
                fn (Builder $query) => $query->where('created_by_id', $user->id),
                fn (Builder $query) => $query->whereHas(
                    'members',
                    fn ($members) => $members->whereKey($user?->id),
                ),
            );
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
