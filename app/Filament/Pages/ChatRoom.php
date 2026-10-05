<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\InteractsWithChatRoom;
use BackedEnum;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Group chat, as staff see it.
 *
 * The same transcript as the student panel, with the difference that staff can
 * open any active group rather than only their own — `GroupChatPolicy::view`
 * grants it, and `availableGroups()` in the trait is what widens the list.
 */
class ChatRoom extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithChatRoom;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|BackedEnum|\UnitEnum|null $navigationGroup = 'Lab';

    protected static ?int $navigationSort = 2;

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
            $this->markGroupReadAction(),
            $this->leaveGroupAction(),
        ];
    }
}
