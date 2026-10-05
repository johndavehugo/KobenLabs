<?php

namespace App\Filament\Concerns;

use App\Enums\GroupMessageType;
use App\Enums\GroupRole;
use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\User;
use App\Services\FileService;
use App\Support\Bytes;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * The group chat transcript, shared by the admin and student panels.
 *
 * Legacy had two near-identical views, `admin/views/messages.js` and
 * `user/views/messages.js`, each with its own copy of the membership check. The
 * logic lives here once and each panel gets a thin Page subclass, because
 * Filament discovers pages by directory and a page cannot serve two panels.
 *
 * Membership is the access control. A group is reachable by a member, by lab
 * staff, or by anyone holding an active join code — which is exactly
 * `GroupChatPolicy`.
 */
trait InteractsWithChatRoom
{
    /**
     * The group being read. In the URL so a transcript can be linked to.
     */
    #[Url(as: 'group', except: null)]
    public ?int $groupId = null;

    /**
     * The pending text of the composer.
     */
    public string $draft = '';

    /**
     * Picked-but-unsent attachments.
     *
     * Declared in the page schema rather than in a modal for the same reason as
     * MyDrive's upload field: RestrictsFileUploadsToSchemaComponents only accepts
     * upload targets that live in a discovered schema.
     *
     * @var array<int, \Illuminate\Http\UploadedFile>
     */
    public array $pendingFiles = [];

    public function user(): User
    {
        return auth()->user();
    }

    /**
     * Groups this user may open.
     *
     * Staff see every group; everybody else sees only their own. Both branches go
     * through the policy rather than through a query string.
     *
     * @return Collection<int, GroupChat>
     */
    public function availableGroups(): Collection
    {
        $user = $this->user();

        return GroupChat::query()
            ->active()
            ->when(
                ! $user->canManageLab(),
                fn ($query) => $query->whereHas(
                    'members',
                    fn ($members) => $members->whereKey($user->id),
                ),
            )
            ->with('creator')
            ->orderBy('name')
            ->get();
    }

    /**
     * The group being read, re-queried and re-authorized on every call.
     *
     * Filament does not restore or re-authorize Eloquent properties on later
     * Livewire requests, so a stale id must never be trusted.
     */
    public function currentGroup(): ?GroupChat
    {
        if ($this->groupId === null) {
            return null;
        }

        $group = GroupChat::query()->with('creator')->find($this->groupId);

        if ($group === null || ! $this->user()->can('view', $group)) {
            $this->groupId = null;

            return null;
        }

        return $group;
    }

    /**
     * Reset a group id the user may not open, so a tampered URL cannot read a
     * transcript.
     */
    public function ensureGroupIsReadable(): void
    {
        if ($this->groupId === null) {
            return;
        }

        if ($this->currentGroup() === null) {
            $this->groupId = null;
        }
    }

    public function mountInteractsWithChatRoom(): void
    {
        $this->ensureGroupIsReadable();
    }

    // -----------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------

    public function chatSchema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Group')
                    ->schema([
                        Select::make('groupId')
                            ->label('Group')
                            ->options(fn (): array => $this->availableGroups()
                                ->mapWithKeys(fn (GroupChat $group): array => [
                                    $group->id => $group->name.($group->isMember($this->user()) ? '' : ' (staff view)'),
                                ])
                                ->all())
                            ->placeholder('Choose a group')
                            ->live()
                            ->searchable()
                            ->helperText(fn (): ?string => $this->currentGroup()?->description),
                    ]),

                Section::make('Send a message')
                    ->visible(fn (): bool => $this->currentGroup() !== null)
                    ->description(function (): ?string {
                        $group = $this->currentGroup();

                        return $group === null
                            ? null
                            : 'Allowed file types: '.$this->allowedGroupFileTypes();
                    })
                    ->schema([
                        Textarea::make('draft')
                            ->label('Message')
                            ->rows(3)
                            ->maxLength((int) config('lab.group_chats.max_message_length')),

                        FileUpload::make('pendingFiles')
                            ->label('Attach')
                            ->multiple()
                            ->maxFiles((int) config('lab.group_chats.max_files_per_message'))
                            ->storeFiles(false)
                            ->visibility('private'),
                    ]),

                Section::make('Transcript')
                    ->visible(fn (): bool => $this->currentGroup() !== null)
                    ->schema([
                        EmbeddedTable::make(),
                    ]),
            ]);
    }

    public function chatTable(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->chatMessages())
            ->columns([
                TextColumn::make('sender.full_name')
                    ->label('From')
                    ->placeholder('System')
                    ->weight('bold'),

                TextColumn::make('body')
                    ->label('Message')
                    ->description(fn (GroupChatMessage $record): ?string => $record->hasAttachment()
                        ? $record->file_name.' ('.Bytes::format((int) $record->file_size).')'
                        : null)
                    ->wrap()
                    ->html(),

                IconColumn::make('type')
                    ->label('')
                    ->icon(fn (GroupChatMessage $record) => match ($record->type) {
                        GroupMessageType::File, GroupMessageType::Image => Heroicon::PaperClip,
                        GroupMessageType::System => Heroicon::InformationCircle,
                        default => null,
                    })
                    ->color(fn (GroupChatMessage $record) => $record->isSystem() ? 'gray' : null),

                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->alignEnd(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                $this->downloadGroupFileAction(),
                $this->deleteGroupMessageAction(),
            ])
            ->emptyStateHeading('No messages yet')
            ->emptyStateDescription('Say something to get started.');
    }

    /**
     * Newest first, paginated. Grouped by day would be nicer; this keeps the
     * transcript readable without a custom view.
     *
     * @return Collection<int, GroupChatMessage>
     */
    public function chatMessages(): Collection
    {
        $group = $this->currentGroup();

        if ($group === null) {
            return collect();
        }

        return $group->chatMessages()
            ->with(['sender'])
            ->latest('created_at')
            ->limit((int) config('lab.group_chats.messages_per_page'))
            ->get();
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------

    public function sendMessageAction(): Action
    {
        return Action::make('sendMessage')
            ->label('Send')
            ->icon(Heroicon::PaperAirplane)
            ->color('primary')
            ->visible(fn (): bool => $this->currentGroup() !== null)
            ->disabled(fn (): bool => trim($this->draft) === '' && $this->pendingFiles === [])
            ->authorize(fn (): bool => auth()->user()?->can('create', GroupChatMessage::class) ?? false)
            ->action(function () {
                $group = $this->currentGroup();
                $service = app(FileService::class);

                if ($group === null) {
                    return;
                }

                $body = trim($this->draft);
                $attachments = $this->pendingFiles;

                if ($body !== '') {
                    $group->chatMessages()->create([
                        'sender_id' => $this->user()->id,
                        'body' => $body,
                        'type' => GroupMessageType::Text,
                    ]);
                }

                foreach ($attachments as $upload) {
                    if (! $upload instanceof UploadedFile) {
                        continue;
                    }

                    try {
                        $service->storeGroupChatFile($upload, $this->user(), $group);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('File rejected')
                            ->body(collect($exception->errors())->flatten()->first())
                            ->send();
                    }
                }

                $this->draft = '';
                $this->pendingFiles = [];

                Notification::make()->success()->title('Sent')->send();
            });
    }

    public function joinByCodeAction(): Action
    {
        return Action::make('joinByCode')
            ->label('Join with code')
            ->icon(Heroicon::Key)
            ->modalHeading('Join a group')
            ->modalDescription('Ask a group administrator for the join code.')
            ->schema([
                TextInput::make('join_code')
                    ->label('Join code')
                    ->required()
                    ->maxLength((int) config('lab.group_chats.join_code_length'))
                    ->autocomplete('off'),
            ])
            ->action(function (array $data): void {
                $group = GroupChat::query()
                    ->active()
                    ->where('join_code', strtoupper(trim((string) $data['join_code'])))
                    ->first();

                // The code is the credential. Say nothing about whether it exists.
                if ($group === null || ! $this->user()->can('join', $group)) {
                    Notification::make()
                        ->danger()
                        ->title('That code is not valid')
                        ->send();

                    return;
                }

                $group->join($this->user());

                $this->groupId = $group->id;

                Notification::make()
                    ->success()
                    ->title("Joined {$group->name}")
                    ->send();
            });
    }

    public function leaveGroupAction(): Action
    {
        return Action::make('leaveGroup')
            ->label('Leave group')
            ->icon(Heroicon::ArrowRightStartOnRectangle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->currentGroup() !== null)
            ->authorize(fn (): bool => auth()->user()?->can('leave', $this->currentGroup() ?? new GroupChat) ?? false)
            ->action(function () {
                $group = $this->currentGroup();

                if ($group === null) {
                    return;
                }

                $group->leave($this->user());

                $this->groupId = null;

                Notification::make()->success()->title('You have left the group')->send();
            });
    }

    public function markGroupReadAction(): Action
    {
        return Action::make('markGroupRead')
            ->label('Mark all read')
            ->icon(Heroicon::Check)
            ->color('gray')
            ->visible(fn (): bool => $this->currentGroup() !== null)
            ->action(function () {
                $group = $this->currentGroup();

                if ($group === null) {
                    return;
                }

                $group->chatMessages()
                    ->whereNull('sender_id')
                    ->orWhere('sender_id', '!=', $this->user()->id)
                    ->get()
                    ->each(fn (GroupChatMessage $message) => $message->markReadBy($this->user()));

                Notification::make()->success()->title('Marked as read')->send();
            });
    }

    private function downloadGroupFileAction(): Action
    {
        return Action::make('downloadGroupFile')
            ->label('Download')
            ->icon(Heroicon::ArrowDownTray)
            ->visible(fn (GroupChatMessage $record): bool => $record->hasAttachment())
            ->url(fn (GroupChatMessage $record): string => route('group-chat.files.download', ['message' => $record->id]))
            ->authorize(fn (GroupChatMessage $record): bool => auth()->user()?->can('download', $record) ?? false);
    }

    private function deleteGroupMessageAction(): Action
    {
        return Action::make('deleteGroupMessage')
            ->label('Delete')
            ->icon(Heroicon::Trash)
            ->color('danger')
            ->requiresConfirmation()
            ->authorize(fn (GroupChatMessage $record): bool => auth()->user()?->can('delete', $record) ?? false)
            ->action(function (GroupChatMessage $record) {
                $record->delete();

                Notification::make()->success()->title('Message deleted')->send();
            });
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function allowedGroupFileTypes(): string
    {
        $extensions = (array) config('lab.uploads.group_chat.allowed_extensions');

        return implode(', ', $extensions);
    }
}