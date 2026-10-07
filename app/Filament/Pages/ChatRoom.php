<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\InteractsWithChatRoom;
use App\Filament\Concerns\ShowsMessengerRooms;
use BackedEnum;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Support\Icons\Heroicon;

/**
 * Group chat, as staff see it.
 *
 * The rooms a staff member sees are the ones they created - see
 * InteractsWithChatRoom::availableGroups(). GroupChatPolicy::view is what authorizes an
 * individual room, and it is deliberately wider than that: staff can be granted access to
 * somebody else's group. What changed is the default list, not the permission.
 *
 * Custom Blade rather than a Filament schema - see ShowsMessengerRooms for why.
 */
class ChatRoom extends Page implements HasActions
{
    use InteractsWithActions;
    use InteractsWithChatRoom;
    use RestrictsFileUploadsToSchemaComponents;
    use ShowsMessengerRooms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|BackedEnum|\UnitEnum|null $navigationGroup = 'Laboratory';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Group Chat';

    protected static ?string $slug = 'group-chat';

    protected string $view = 'filament.pages.chat-room';

    public function mount(): void
    {
        $this->mountInteractsWithChatRoom();
    }

    /**
     * Page-level actions only.
     *
     * Everything returned here is rendered by Filament's page header as well as cached for
     * lookup, so anything listed is drawn twice if this view draws it too - which is how the
     * group controls ended up with a copy in the header next to a copy on the group they act
     * on. Only createGroup belongs to the page: it makes a new group and applies to none of
     * the ones listed.
     *
     * renameGroup, markGroupRead and leaveGroup are deliberately absent, and still work.
     * InteractsWithActions::resolveAction falls back to a naming convention - the name
     * `renameGroup` resolves to this component's `renameGroupAction()` method when it is not
     * in the cache - so a click finds its handler without being a header action. That is what
     * lets them live on the open group in the Blade, where they read as group controls rather
     * than page controls.
     *
     * Join-by-code is absent because a staff member has no use for it, and offering it invites
     * a teacher into a student's room by typing a code a student was given.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->createGroupAction(),
        ];
    }
}
