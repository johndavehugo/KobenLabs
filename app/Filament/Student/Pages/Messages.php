<?php

namespace App\Filament\Student\Pages;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\Bytes;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * A student's mailbox: broadcasts plus anything sent to them directly.
 *
 * The legacy controller had index/show/store/markAsRead/getUnreadCount, but no
 * view ever linked to it, so the feature was unreachable from the UI. It is
 * surfaced here rather than as a resource because the list has to be "mine" —
 * MessagePolicy also lets a sender read their own message, which has no place
 * in a student's mailbox.
 *
 * No compose form: MessagePolicy::create is staff-only, so staff compose from
 * the admin panel.
 */
class Messages extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Messages';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Messages';

    protected static ?string $slug = 'messages';

    /**
     * The open message, so its body and attachments render beside the list
     * instead of on a second page.
     */
    #[Url(as: 'message', except: null)]
    public ?int $messageId = null;

    public function user(): User
    {
        return auth()->user();
    }

    /**
     * Re-queried and re-authorized on every call.
     *
     * Filament does not restore or re-authorize Eloquent properties between
     * Livewire requests, so a guessed id in the URL must never reveal another
     * person's mail. The nulling is deliberate: a bad id drops back to the
     * mailbox rather than leaking that the id exists.
     */
    public function currentMessage(): ?Message
    {
        if ($this->messageId === null) {
            return null;
        }

        $message = Message::query()
            ->with(['sender', 'attachments'])
            ->find($this->messageId);

        if ($message === null || ! $this->user()->can('view', $message)) {
            $this->messageId = null;

            return null;
        }

        return $message;
    }

    public function mount(): void
    {
        $this->markOpenMessageRead();
    }

    /**
     * Covers every route into the open message — clicking it in the list, or
     * arriving on a link.
     *
     * mount() alone was not enough: it runs once on a fresh load, so a message
     * opened from the list stayed unread until the next reload and the badge
     * never cleared.
     */
    public function updatedMessageId(): void
    {
        $this->markOpenMessageRead();
    }

    /**
     * Opening a message marks it read, which is what the legacy `markAsRead`
     * endpoint made a separate click.
     */
    public function markOpenMessageRead(): void
    {
        $this->currentMessage()?->markReadBy($this->user());
    }

    // -----------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Unread')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('unread')
                            ->label('Unread messages')
                            ->state(fn (): int => $this->unreadCount()),
                    ]),

                Section::make($this->openMessageHeading())
                    ->visible(fn (): bool => $this->currentMessage() !== null)
                    ->schema([
                        TextEntry::make('subject')
                            ->label('Subject')
                            ->weight('bold')
                            ->columnSpanFull(),
                        TextEntry::make('sender.full_name')
                            ->label('From')
                            ->placeholder('A deleted account'),
                        TextEntry::make('created_at')
                            ->label('Received')
                            ->dateTime(),
                        TextEntry::make('body')
                            ->label('')
                            ->hiddenLabel()
                            ->prose()
                            ->columnSpanFull(),
                        TextEntry::make('attachments')
                            ->label('Attachments')
                                                    // A plain Page has no $record, so this reads the
                                                    // resolved message rather than a record path.
                            ->state(fn (): string => $this->attachmentList($this->currentMessage()))
                            ->columnSpanFull(),
                    ]),

                Section::make('Mailbox')->schema([
                    EmbeddedTable::make(),
                ]),
            ]);
    }

    private function attachmentList(?Message $record): string
    {
        if ($record === null || $record->attachments->isEmpty()) {
            return 'No attachments';
        }

        return $record->attachments
            ->map(fn (MessageAttachment $attachment): string => sprintf(
                '%s (%s) — %s',
                $attachment->original_name,
                Bytes::format((int) $attachment->size),
                route('messages.attachments.download', ['attachment' => $attachment->id]),
            ))
            ->implode("\n");
    }

    private function openMessageHeading(): string
    {
        $message = $this->currentMessage();

        return $message === null ? 'Message' : 'Message: '.$message->subject;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->mailbox())
            ->columns([
                IconColumn::make('read_at')
                    // Read state is the entire point of this column, and an
                    // unlabelled envelope icon announces nothing to a screen reader.
                    ->label('Status')
                    ->tooltip(fn (Message $record): string => $this->isUnread($record) ? 'Unread' : 'Read')
                    ->icon(fn (Message $record): ?string => $this->isUnread($record)
                        ? Heroicon::Envelope
                        : null)
                    ->color('warning'),

                TextColumn::make('subject')
                    ->label('Subject')
                    ->weight(fn (Message $record): string => $this->isUnread($record) ? 'bold' : 'normal')
                    ->description(fn (Message $record): string => Str::limit((string) $record->body, 70)),

                TextColumn::make('sender.full_name')
                    ->label('From')
                    ->placeholder('A deleted account'),

                TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::ArrowRightCircle)
                    ->action(function (Message $record) {
                        $this->messageId = $record->id;

                        // Assigned server-side here, which does not fire
                        // updatedMessageId(), so mark it here as well.
                        $this->markOpenMessageRead();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No messages')
            ->emptyStateDescription('Messages from your teachers will appear here.');
    }

    // -----------------------------------------------------------------
    // Data
    // -----------------------------------------------------------------

    /**
     * @return Collection<int, Message>
     */
    public function mailbox(): Collection
    {
        return Message::query()
            ->visibleTo($this->user())
            ->with(['sender', 'recipients'])
            ->latest('created_at')
            ->limit((int) config('lab.messages.per_page'))
            ->get();
    }

    public function unreadCount(): int
    {
        return Message::query()
            ->visibleTo($this->user())
            ->unreadBy($this->user())
            ->count();
    }

    public function isUnread(Message $record): bool
    {
        return ! $record->isReadBy($this->user());
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------

    public function markAllReadAction(): Action
    {
        return Action::make('markAllRead')
            ->label('Mark all read')
            ->icon(Heroicon::Check)
            ->color('gray')
            ->disabled(fn (): bool => $this->unreadCount() === 0)
            ->action(function () {
                $count = $this->unreadCount();

                $this->mailbox()
                    ->filter(fn (Message $message): bool => $this->isUnread($message))
                    ->each(fn (Message $message) => $message->markReadBy($this->user()));

                Notification::make()
                    ->success()
                    ->title($count === 1 ? '1 message marked read' : "{$count} messages marked read")
                    ->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->markAllReadAction(),
        ];
    }
}
