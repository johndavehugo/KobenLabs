<?php

namespace App\Filament\Student\Pages;

use App\Filament\Concerns\InteractsWithChatRoom;
use BackedEnum;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Group chat, as a student sees it: the groups you belong to, plus the ability
 * to redeem a join code.
 *
 * A Page rather than a resource because a student must reach their own
 * transcripts, not browse every group.
 */
class ChatRoom extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithChatRoom;
    use InteractsWithSchemas;

    /**
     * The composer holds a FileUpload, so the restriction is what stops an upload
     * being aimed at `draft` or `groupId` instead. Filament does not add it to
     * Pages on its own.
     */
    use RestrictsFileUploadsToSchemaComponents;

    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Group Chat';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Group Chat';

    protected static ?string $slug = 'group-chat';

    public function mount(): void
    {
        $this->mountInteractsWithChatRoom();
    }

    public function content(Schema $schema): Schema
    {
        return $this->chatSchema($schema);
    }

    public function table(Table $table): Table
    {
        return $this->chatTable($table);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->sendMessageAction(),
            $this->joinByCodeAction(),
            $this->markGroupReadAction(),
            $this->leaveGroupAction(),
        ];
    }
}