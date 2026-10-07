<?php

namespace App\Filament\Concerns;

use App\Enums\GroupMessageType;
use App\Enums\GroupRole;
use App\Events\GroupChatTyping;
use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\User;
use App\Services\FileService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * The messenger layout, shared by both panels.
 *
 * Replaces the previous Select-plus-EmbeddedTable schema. That shape could not express
 * what the page is for: a room has a name, a roster and a stream, and a schema renders
 * them as three stacked sections with a group dropdown on top. Which meant the group you
 * were reading was always the least visible thing on the page.
 *
 * Custom Blade, for the same reason LiveScreens is custom Blade - see that page for the
 * fuller reasoning. The difference that matters here is that this is a conversation, not
 * a read-only grid, so the composer and the roster are markup rather than schema
 * components and the transcript is rendered directly instead of through a table.
 *
 * Both panels use this trait, so staff and students get the same room and only the
 * abilities differ - which are the policy's job, not the view's.
 */
trait ShowsMessengerRooms
{
    /**
     * The composer takes a plain file input rather than Filament's FileUpload.
     *
     * FileUpload renders a drop zone with previews, a progress bar and a cancel button per
     * file - a good deal of furniture for what is, here, "attach a screenshot of the error
     * I am looking at". It also stores through Livewire's temporary upload store, which is
     * what this trait reads, so the storage half is the same either way.
     */
    use WithFileUploads;

    /**
     * How many messages to keep in the DOM.
     *
     * Bounded because this is an unbounded table: a room used for a whole term's code
     * examples would otherwise render every one of them on every request.
     */
    public function transcriptLimit(): int
    {
        return (int) config('lab.group_chats.messages_per_page');
    }

    /**
     * The transcript, oldest first.
     *
     * Oldest first because a chat reads downwards. The embedded table this replaced
     * ordered newest first, which put the message you had just sent at the top and
     * required scrolling up to read the conversation leading to it.
     *
     * @return Collection<int, GroupChatMessage>
     */
    public function transcript(): Collection
    {
        $group = $this->currentGroup();

        if ($group === null) {
            return collect();
        }

        return $group->chatMessages()
            ->with(['sender'])
            ->oldest('created_at')
            ->oldest('id')
            ->limit($this->transcriptLimit())
            ->get()
            ->sortBy(fn (GroupChatMessage $message): string => $message->created_at->format('U.u').'-'.$message->id)
            ->values();
    }

    /**
     * Who is in the room.
     *
     * The creator first, then everyone else alphabetically. The creator is listed first
     * because they are the person who can rename the room or remove somebody, and finding
     * that out by scrolling a roster of thirty is not reasonable.
     *
     * @return Collection<int, User>
     */
    public function roster(): Collection
    {
        $group = $this->currentGroup();

        if ($group === null) {
            return collect();
        }

        $creatorId = $group->created_by_id;

        return $group->members
            ->sortBy(fn ($member): array => [
                $member->id === $creatorId ? 0 : 1,
                mb_strtolower((string) $member->full_name),
            ])
            ->values();
    }

    /**
     * Whether the signed-in user may rename this room.
     *
     * Asked of the policy rather than derived here, so the button in the UI and the check
     * that would actually stop the rename cannot disagree.
     */
    public function canRenameRoom(): bool
    {
        $group = $this->currentGroup();

        return $group !== null && $this->user()->can('update', $group);
    }

    /**
     * Whether the signed-in user created this room.
     */
    public function ownsRoom(): bool
    {
        $group = $this->currentGroup();

        return $group !== null && $group->created_by_id === $this->user()->id;
    }

    /**
     * The join code, for sharing.
     *
     * Only offered to somebody who can already see the room, which the creator always
     * can. Students joining by code never need to read it back.
     */
    public function joinCode(): ?string
    {
        return $this->currentGroup()?->join_code;
    }

    /**
     * Values the typing indicator needs in the browser.
     *
     * The signed-in user's id so their own keystrokes can be discarded on arrival - a
     * websocket channel delivers to everybody including the sender, and "you are typing…"
     * under your own composer is noise.
     *
     * The decay window comes from config rather than being written again in JavaScript, so
     * the two cannot drift apart. The view renders this once, as a data attribute; there is no
     * inline script.
     */
    public function typingContext(): array
    {
        return [
            'userId' => $this->user()->id,
            'ttlSeconds' => (int) config('lab.group_chats.typing_expires_after_seconds'),
        ];
    }

    #[On('koben:chat-typing')]
    public function onTyping(): void
    {
        $this->broadcastTyping();
    }

    /**
     * Broadcast that this user is typing.
     *
     * Called on a throttle rather than per keystroke. A burst of keydowns would otherwise be
     * one broadcast each - so typing a word would fire more messages than the room will ever
     * display, each of which every other member has to receive and discard. `throttle` keeps
     * that to roughly one per second, which is faster than anyone can read a typing indicator.
     *
     * Deliberately does nothing to the database, and deliberately does not fail the request if
     * the broadcaster is unreachable: a teacher mid-sentence should not have their message
     * rejected because a websocket was down, and the indicator is decoration.
     */
    public function broadcastTyping(): void
    {
        $group = $this->currentGroup();

        if ($group === null || $this->draft === '') {
            return;
        }

        // RateLimiter::attempt, not a throttle() method. The attempt runs the closure only if
        // the key has fewer than $maxAttempts hits in the window, and consumes a hit when it
        // does - which is the behaviour wanted here. Every other page in this app reaches
        // RateLimiter through route middleware; there is no throttle() on a Livewire component
        // or its trait, so calling one threw BadMethodCallException on every keystroke and
        // took the whole page down with it.
        app(RateLimiter::class)->attempt(
            'chat-typing-'.$group->id.'-'.$this->user()->id,
            1,
            function () use ($group): void {
                try {
                    GroupChatTyping::dispatch($group->id, $this->user()->id, $this->user()->full_name);
                } catch (\Throwable $exception) {
                    // Reported rather than swallowed: a broadcaster failing on every keystroke
                    // is worth knowing about, but it must not interrupt typing.
                    report($exception);
                }
            },
            // The fourth argument is the window in seconds. One, matching the "roughly one
            // broadcast per second" the comment above promises - the default of 60 would
            // have reduced a burst of typing to a single event per minute.
            1,
        );
    }

    /**
     * Normalise whatever the composer collected into an UploadedFile.
     *
     * A Livewire temporary upload is a file already sitting in the store with its own
     * name, so it is re-wrapped rather than copied. The fifth constructor argument marks
     * the file as being in test mode, which is what stops Symfony asserting that the path
     * is a genuine HTTP upload - without it `is_uploaded_file()` fails and the wrapping
     * throws.
     *
     * Anything else is passed through if it already is an UploadedFile, and discarded if
     * it is neither. A stray value in the property should drop one attachment, not stop
     * the message.
     */
    private function toUploadedFile(mixed $upload): ?UploadedFile
    {
        // Order matters, and getting it wrong is silent. Livewire's TemporaryUploadedFile
        // *extends* Symfony's UploadedFile, so an `instanceof UploadedFile` test placed first
        // matches it and hands the temporary file straight through to FileService - which then
        // calls getClientOriginalName() on it. That method is declared on the subclass but reads
        // a property the subclass does not have, so it fails with "Undefined property:
        // ...::$name" the moment a real attachment is sent.
        if ($upload instanceof TemporaryUploadedFile) {
            // getFilename() is the name Livewire actually keeps. The browser-supplied MIME is
            // not available at all, which is fine: FileService sniffs the contents rather than
            // trusting what the client claims.
            return new UploadedFile(
                $upload->getRealPath(),
                $upload->getFilename(),
                null,
                null,
                true,
            );
        }

        if ($upload instanceof UploadedFile) {
            return $upload;
        }

        return null;
    }

    /**
     * Send whatever is in the composer.
     *
     * Body and files are one gesture, but each file becomes its own message row - that is
     * the shape `group_chat_messages` was built with, and it keeps a download route that
     * addresses one file rather than parsing a manifest.
     */
    public function sendMessage(): void
    {
        $group = $this->currentGroup();

        if ($group === null) {
            return;
        }

        $body = trim((string) $this->draft);
        $attachments = $this->pendingFiles;

        if ($body === '' && $attachments === []) {
            return;
        }

        $service = app(FileService::class);

        if ($body !== '') {
            $this->announce(
                $group->chatMessages()->create([
                    'sender_id' => $this->user()->id,
                    'body' => $body,
                    'type' => GroupMessageType::Text,
                ]),
                $group,
            );
        }

        foreach ($attachments as $upload) {
            // The composer is a plain file input, so in the browser these arrive as
            // Livewire's TemporaryUploadedFile. Tests set the property directly and hand
            // over a real UploadedFile. Both are accepted here so that FileService keeps
            // exactly one set of extension, MIME and quota checks, rather than the tests
            // exercising a path production never takes.
            $normalised = $this->toUploadedFile($upload);

            if ($normalised === null) {
                continue;
            }

            try {
                $this->announce(
                    $service->storeGroupChatFile($normalised, $this->user(), $group),
                    $group,
                );
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

        $this->dispatch('$refresh');
    }

    /**
     * Rename the room, if the policy allows it.
     *
     * Re-checked here rather than trusted from the button being visible, because the
     * button is rendered from a cached component state that may predate the last change
     * of hands.
     */
    public function renameRoom(?string $name): void
    {
        $group = $this->currentGroup();

        if ($group === null || ! $this->user()->can('update', $group)) {
            Notification::make()->danger()->title('You cannot rename this group')->send();

            return;
        }

        $name = trim((string) $name);

        if ($name === '') {
            return;
        }

        $group->forceFill(['name' => mb_substr($name, 0, 100)])->save();

        Notification::make()->success()->title('Group renamed')->send();

        $this->dispatch('$refresh');
    }

    /**
     * Create a room and open it.
     *
     * The creator becomes its first member and, per GroupChatPolicy, cannot leave it -
     * which is right for a room that exists to serve one class: somebody has to be able to
     * rename it and hand out the code.
     *
     * @param  array<string, mixed>  $data
     */
    public function createRoom(array $data): void
    {
        $user = $this->user();

        if (! $user->can('create', GroupChat::class)) {
            Notification::make()->danger()->title('You cannot create groups')->send();

            return;
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            Notification::make()->danger()->title('Give the group a name')->send();

            return;
        }

        $group = GroupChat::query()->create([
            'name' => mb_substr($name, 0, 100),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'join_code' => GroupChat::generateJoinCode(),
            'created_by_id' => $user->id,
        ]);

        $group->join($user, GroupRole::Admin);

        $this->groupId = $group->id;

        Notification::make()
            ->success()
            ->title('Group created')
            ->body('Share the join code: '.$group->join_code)
            ->send();

        $this->dispatch('$refresh');
    }

    /**
     * The "New Group" control.
     *
     * A Filament Action rather than a hand-rolled button plus a dispatched event. The first
     * version of this page rendered `<button wire:click="$dispatch('open-create-group')">` and
     * nothing ever listened for it, so the button did nothing at all - and it failed silently,
     * which is the worst way for a control to fail. An Action rendered with {{ }} carries its
     * own schema, modal and handler, so there is nothing to forget to wire up.
     */
    public function createGroupAction(): Action
    {
        return Action::make('createGroup')
            ->label('New Group')
            ->icon(Heroicon::Plus)
            ->color('gray')
            ->modalHeading('New group')
            ->modalDescription('Students join with the code this produces.')
            // No visible() guard here. Which people get this control is decided by where it
            // is registered - the staff page lists it and the student page does not - and the
            // Blade chooses which row to render it in. A visible() closure reading the gate
            // here was evaluated against a page with no authenticated user during action
            // caching and reported false, hiding New Group from the teachers who are the only
            // people who may use it.
            ->schema([
                TextInput::make('name')
                    ->label('Group name')
                    ->required()
                    ->maxLength(100)
                    // Plain subject names, not "Web Development" or "BSIT 2A". Whoever opens the dialog
                    // may be teaching history, mathematics or anything else, and an example
                    // that names a computing course quietly tells them this is not for their
                    // class. The placeholder should read as an example of a group, not as a
                    // suggestion about what to teach.
                    ->placeholder('Year 10 - History'),
                Textarea::make('description')
                    ->label('Description (optional)')
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(fn (array $data) => $this->createRoom($data));
    }

    /**
     * Rename the current room.
     *
     * Also an Action, for the same reason. The button it replaces dispatched an event that
     * nothing handled.
     */
    public function renameGroupAction(): Action
    {
        return Action::make('renameGroup')
            ->label('Rename')
            ->icon(Heroicon::Pencil)
            ->color('gray')
            ->modalHeading('Rename group')
            ->schema([
                TextInput::make('name')
                    ->label('Group name')
                    ->required()
                    ->maxLength(100)
                    ->default(fn (): ?string => $this->currentGroup()?->name),
            ])
            ->action(fn (array $data) => $this->renameRoom($data['name'] ?? null));
    }

    /**
     * The two things you can do to the open group, behind one control.
     *
     * An ActionGroup rather than two buttons. Rename and Leave are not used together - a
     * teacher renaming a group mid-lesson is not a teacher who is about to leave it - and two
     * separate buttons put a destructive action one stray click away from an everyday one, on
     * every screen, for both staff and students. Alongside the join code it also left the group
     * header reading as a row of unrelated controls rather than one row: the code, then Rename,
     * then Mark all read, then Leave.
     *
     * Leave is inside here rather than omitted: the owner cannot leave their own group
     * (GroupChatPolicy::leave refuses when created_by_id is the caller), so this is the only
     * place it is offered to the members it applies to.
     */
    public function groupControlsActionGroup(): ActionGroup
    {
        // The array is the constructor argument: ActionGroup::make() takes the members up
        // front, not a name, so there is no ->name() call here.
        return ActionGroup::make([
            $this->renameGroupAction(),
            $this->leaveGroupAction(),
        ])
            ->label('Group options')
            ->icon(Heroicon::EllipsisVertical)
            ->color('gray');
    }

    /**
     * Open a room.
     *
     * Marking it read here rather than leaving it to a button is the whole point: read state
     * exists so the sidebar can show what is unread, and a badge that only clears when you
     * find and press a control is a badge that stays wrong all lesson. Opening the
     * conversation is the signal that it has been read.
     */
    public function selectGroup(?int $groupId): void
    {
        if ($groupId === null) {
            $this->groupId = null;
            $this->dispatch('$refresh');

            return;
        }

        // currentGroup() re-authorizes, so assigning the id and reading it back is the
        // check: a group this person may not see resolves to null and clears the
        // selection rather than rendering it.
        $this->groupId = $groupId;

        $group = $this->currentGroup();

        if ($group === null) {
            $this->groupId = null;
        } else {
            $group->markAllReadBy($this->user());
        }

        $this->draft = '';
        $this->pendingFiles = [];

        $this->dispatch('$refresh');
    }
}
