<?php

namespace App\Filament\Pages;

use App\Models\Message;
use App\Models\User;
use App\Models\YearSection;
use App\Services\FileService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Staff view of the message mailbox: compose, and a list of what has been sent.
 *
 * Legacy's `MessageController::store()` was reachable only by teachers and sent
 * to explicit id lists, so "message every student in BSIT 2A" meant the teacher
 * hand-assembled ids. The year/section recipient here closes that.
 */
class Messages extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    use InteractsWithTable;

    /**
     * The composer holds a FileUpload, so this is what stops an upload being
     * aimed at `subject` or another property instead.
     */
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|BackedEnum|\UnitEnum|null $navigationGroup = 'Lab';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Messages';

    protected static ?string $slug = 'messages';

    /**
     * The open message, for reading what was sent.
     */
    #[Url(as: 'message', except: null)]
    public ?int $messageId = null;

    // -----------------------------------------------------------------
    // Composer state
    // -----------------------------------------------------------------

    public string $subject = '';

    public string $body = '';

    /** @var array<int, int> */
    public array $recipientIds = [];

    public ?int $yearSectionId = null;

    public bool $isBroadcast = false;

    /** @var array<int, UploadedFile> */
    public array $pendingFiles = [];

    public function user(): User
    {
        return auth()->user();
    }

    /**
     * Re-queried and re-authorized. Staff can read anything they could have sent,
     * but not a private message between two teachers — MessagePolicy has no
     * staff override for `view`, deliberately.
     */
    public function currentMessage(): ?Message
    {
        if ($this->messageId === null) {
            return null;
        }

        $message = Message::query()->with(['sender', 'attachments'])->find($this->messageId);

        if ($message === null || ! $this->user()->can('view', $message)) {
            $this->messageId = null;

            return null;
        }

        return $message;
    }

    // -----------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Compose')
                    ->description('Attachments are validated by FileService: the same allowlist and quota as the personal drive.')
                    ->schema([
                        TextInput::make('subject')
                            ->label('Subject')
                            ->required()
                            ->maxLength(255),

                        Textarea::make('body')
                            ->label('Message')
                            ->required()
                            ->rows(5),

                        Select::make('recipientIds')
                            ->label('Recipients')
                            ->options(fn (): array => User::query()
                                ->orderBy('username')
                                ->pluck('username', 'id')
                                ->all())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            // A whole year/section supersedes picking people.
                            ->disabled(fn (): bool => $this->yearSectionId !== null),

                        Select::make('yearSectionId')
                            ->label('...or a whole year and section')
                            ->options(YearSection::options()->all())
                            ->searchable()
                            ->live()
                            ->placeholder('Pick individuals instead'),

                        Toggle::make('isBroadcast')
                            ->label('Broadcast to every student')
                            ->helperText('Students who enrol later will still see this.')
                            ->live(),

                        FileUpload::make('pendingFiles')
                            ->label('Attachments')
                            ->multiple()
                            ->maxFiles(5)
                            ->storeFiles(false)
                            ->visibility('private'),
                    ]),

                Section::make($this->openMessageHeading())
                    ->visible(fn (): bool => $this->currentMessage() !== null)
                    ->schema([
                        TextEntry::make('subject')
                            ->label('Subject')
                            ->weight('bold')
                            ->columnSpanFull(),
                        TextEntry::make('sender.full_name')
                            ->label('From'),
                        TextEntry::make('created_at')
                            ->label('Sent')
                            ->dateTime(),
                        TextEntry::make('is_broadcast')
                            ->label('Broadcast')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                        TextEntry::make('recipients')
                            ->label('Recipients')
                            ->placeholder('Everyone')
                            ->formatStateUsing(fn (Message $record): string => $record->recipients->count() === 0
                                ? 'Everyone'
                                : $record->recipients->count().' people')
                            ->columnSpanFull(),
                        TextEntry::make('body')
                            ->label('')
                            ->hiddenLabel()
                            ->prose()
                            ->columnSpanFull(),
                    ]),

                Section::make('Sent')->schema([
                    EmbeddedTable::make(),
                ]),
            ]);
    }

    private function openMessageHeading(): string
    {
        $message = $this->currentMessage();

        return $message === null ? 'Message' : 'Message: '.$message->subject;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->sentMessages())
            ->columns([
                TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->description(fn (Message $record): string => Str::limit((string) $record->body, 70)),

                IconColumn::make('is_broadcast')
                    ->label('Broadcast')
                    ->boolean(),

                TextColumn::make('recipients_count')
                    ->label('Recipients')
                    ->counts('recipients'),

                TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::ArrowRightCircle)
                    ->action(fn (Message $record) => $this->messageId = $record->id),

                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (Message $record): bool => $this->user()->can('delete', $record))
                    ->action(function (Message $record) {
                        $record->delete();

                        if ($this->messageId === $record->id) {
                            $this->messageId = null;
                        }

                        Notification::make()->success()->title('Message deleted')->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nothing sent yet')
            ->emptyStateDescription('Compose a message above.');
    }

    // -----------------------------------------------------------------
    // Data
    // -----------------------------------------------------------------

    /**
     * Only what this user sent. Not every message: a private message between two
     * teachers is not lab business, and MessagePolicy refuses to read it.
     *
     * @return Collection<int, Message>
     */
    public function sentMessages(): Collection
    {
        return Message::query()
            ->where('sender_id', $this->user()->id)
            ->with('recipients')
            ->latest('created_at')
            ->limit((int) config('lab.messages.per_page'))
            ->get();
    }

    /**
     * Who a compose will reach.
     *
     * A broadcast ignores the explicit list; a year/section and an explicit list
     * are mutually exclusive, enforced by the year/section field disabling the
     * recipient picker.
     *
     * @return array<int, int>
     */
    public function resolveRecipientIds(): array
    {
        if ($this->isBroadcast) {
            return [];
        }

        if ($this->yearSectionId !== null) {
            return YearSection::query()
                ->find($this->yearSectionId)
                ?->students()
                ->pluck('id')
                ->all() ?? [];
        }

        return array_values(array_unique(array_map('intval', $this->recipientIds)));
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------

    public function sendAction(): Action
    {
        return Action::make('send')
            ->label('Send')
            ->icon(Heroicon::PaperAirplane)
            ->color('primary')
            ->disabled(fn (): bool => $this->resolveRecipientIds() === [] && ! $this->isBroadcast)
            ->authorize(fn (): bool => $this->user()->can('create', Message::class))
            ->action(function () {
                $service = app(FileService::class);

                $recipientIds = $this->resolveRecipientIds();

                if ($recipientIds === [] && ! $this->isBroadcast) {
                    Notification::make()
                        ->danger()
                        ->title('Pick at least one recipient')
                        ->send();

                    return;
                }

                $message = (new Message)
                    ->forceFill([
                        'sender_id' => $this->user()->id,
                        'subject' => $this->subject,
                        'body' => $this->body,
                    ]);

                $message->sendTo($recipientIds, $this->isBroadcast);

                foreach ($this->pendingFiles as $upload) {
                    if (! $upload instanceof UploadedFile) {
                        continue;
                    }

                    try {
                        $service->storeMessageAttachment($upload, $this->user(), $message->id);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Attachment rejected')
                            ->body(collect($exception->errors())->flatten()->first())
                            ->send();
                    }
                }

                $reached = $this->isBroadcast
                    ? 'every student'
                    : count($recipientIds).' people';

                $this->resetComposer();
                $this->messageId = $message->id;

                Notification::make()
                    ->success()
                    ->title("Sent to {$reached}")
                    ->send();
            });
    }

    private function resetComposer(): void
    {
        $this->subject = '';
        $this->body = '';
        $this->recipientIds = [];
        $this->yearSectionId = null;
        $this->isBroadcast = false;
        $this->pendingFiles = [];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->sendAction(),
        ];
    }
}
