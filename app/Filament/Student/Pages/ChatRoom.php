<?php

namespace App\Filament\Student\Pages;

use App\Filament\Concerns\InteractsWithChatRoom;
use App\Filament\Concerns\ShowsMessengerRooms;
use BackedEnum;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;

/**
 * Group chat, as a student sees it: the groups they have joined with a code.
 *
 * A Page rather than a resource because a student must reach their own rooms, not browse
 * every group - and `availableGroups()` is what makes that true rather than assumed.
 *
 * Custom Blade rather than a Filament schema - see ShowsMessengerRooms.
 */
class ChatRoom extends Page implements HasActions
{
    use InteractsWithActions;
    use InteractsWithChatRoom;

    /**
     * The composer takes a file input, so the restriction is what stops an upload being
     * aimed at `draft` or `groupId` instead. Filament does not add it to Pages on its own.
     */
    use RestrictsFileUploadsToSchemaComponents;

    use ShowsMessengerRooms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Group Chat';

    protected static ?int $navigationSort = 3;

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
     * See the note in the admin ChatRoom: listing an action here both caches it and draws it
     * in the page header, so it must be something that applies to the page rather than to the
     * open group. joinByCode is the student's one way in and creates nothing, so it qualifies.
     * renameGroup, markGroupRead and leaveGroup do not, and resolve by naming convention
     * instead - which is what keeps them on the group in the Blade with no second copy.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->joinByCodeAction(),
        ];
    }
}
