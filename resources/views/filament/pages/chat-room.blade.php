<x-filament-panels::page>
{{--
    Group chat, laid out the way a conversation reads: rooms on the left, the room itself
    on the right.

    Wrapped in Filament's page component rather than a bare div. This is what supplies the
    action-modal container, and a bare div omits it: the modal container rendered, but a
    mounted action had nowhere to draw itself, so pressing New Group produced no visible
    change and no error. The same wrapper also lays out the page header, which is why there
    is no manual pt-10 here to keep the first row of buttons clear of the breadcrumb bar.

    Custom Blade rather than a Filament schema, because a schema renders components in a
    fixed vertical order. That order cannot express this: the room you are reading has to
    be the largest thing on screen and the one you are not reading must be beside it, which
    is a two-dimensional arrangement, and it is why the previous version - a Select above a
    composer above an EmbeddedTable - made the group you were in the least visible thing on
    the page.

    Accessibility notes:
      - The room list is a real list of buttons, each naming the group and its size.
      - The transcript is a log with role="log", so assistive technology announces new
        messages without stealing focus from the composer.
      - System messages ("X joined") are marked as such and read out as status, so a room
        filling up with join notices is not read as a conversation.
      - The composer is a form: Enter sends, Shift+Enter starts a new line.
--}}
<div class="flex h-[calc(100vh-16rem)] min-h-[32rem] gap-4">
    {{-- Rooms ---------------------------------------------------------- --}}
    <div class="flex w-64 shrink-0 flex-col rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5">
        <div class="border-b border-gray-200 p-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Groups</h2>

{{-- No button here.

                 Every action is registered in the page's getHeaderActions(), which is both
                 what lets a click resolve and what makes Filament's own page header render
                 it. This Blade used to render the same actions as well, so every control
                 appeared twice: once from the header and once from here. Registering in one
                 place and drawing in one place is the fix, not drawing them here too.
            --}}
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-2">
            @forelse ($this->availableGroups() as $room)
                @php($selected = $this->currentGroup()?->id === $room->id)

@php($unread = $room->unreadCountFor())

                <button
                    type="button"
                    wire:click="selectGroup({{ $room->id }})"
                    wire:key="room-{{ $room->id }}"
                    @class([
                        'mb-1 flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left transition',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
                        'bg-gray-100 dark:bg-white/10' => $selected,
                        'hover:bg-gray-50 dark:bg-white/5' => ! $selected,
                    ])
                    @if ($selected) aria-current="true" @endif
                >
                    <span class="min-w-0">
                        <span @class([
                            'block truncate text-sm',
                            'font-semibold text-gray-950 dark:text-white' => $selected,
                            'font-medium text-gray-700 dark:text-gray-200' => ! $selected,
                        ])>
                            {{ $room->name }}
                        </span>

                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            {{ $room->members->count() }} {{ $room->members->count() === 1 ? 'member' : 'members' }}
                        </span>
                    </span>

                    {{-- Unread count, replacing the member count in the reading position only when
                         there is something to read. Marked aria-hidden and paired with the
                         sr-only text below, because a bare number in the list is announced
                         with no indication of what it counts. --}}
                    @if ($unread > 0)
                        <span
                            class="shrink-0 rounded-full bg-primary-600 px-2 py-0.5 text-xs font-semibold text-white"
                            title="{{ $unread }} unread"
                        >
                            {{ $unread > 99 ? '99+' : $unread }}
                            <span class="sr-only"> unread messages</span>
                        </span>
                    @endif
                </button>
            @empty
                <div class="px-3 py-6 text-center">
                    <x-filament::icon
                        icon="heroicon-o-chat-bubble-left-right"
                        class="mx-auto h-8 w-8 text-gray-300 dark:text-gray-600"
                    />

                    <p class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                        No groups yet
                    </p>

<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if (auth()->user()->can('create', \App\Models\GroupChat::class))
                            Create one above to get started
                        @else
                            Join one with a code above
                        @endif
                    </p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- The room ------------------------------------------------------- --}}
    @php($group = $this->currentGroup())

    <div class="flex min-w-0 flex-1 flex-col rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5">
        @if ($group === null)
            <div class="flex flex-1 flex-col items-center justify-center p-8 text-center">
                <x-filament::icon
                    icon="heroicon-o-chat-bubble-left-right"
                    class="h-12 w-12 text-gray-300 dark:text-gray-600"
                />

                <h2 class="mt-4 text-lg font-semibold text-gray-950 dark:text-white">
                    {{ auth()->user()->can('create', \App\Models\GroupChat::class) ? 'Welcome to Group Chat' : 'Welcome to Groups' }}
                </h2>

<p class="mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">
                    @if (auth()->user()->can('create', \App\Models\GroupChat::class))
                        Start chatting and send files to your class.
                        Use <strong>New Group</strong> to get started.
                    @else
                        Your teacher will give you a join code for the group they made for
                        your class. Use <strong>Join with code</strong> to enter it.
                    @endif
                </p>
            </div>
        @else
            {{-- Header --}}
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 p-3 dark:border-white/10">
                <div class="min-w-0">
                    <h2 class="truncate text-base font-semibold text-gray-950 dark:text-white">
                        {{ $group->name }}
                    </h2>

                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                        {{ count($this->roster()) }} {{ count($this->roster()) === 1 ? 'member' : 'members' }}
                        @if ($group->description)
                            &middot; {{ $group->description }}
                        @endif
                    </p>
                </div>

{{-- Everything here acts on the open group, so it sits on the open group rather than in
                     the page header. The page header is for what you can do to the page -
                     New Group, Join with code - which is why rename, mark-read and leave read
                     as misplaced there: they are not page actions, they are group actions, and
                     putting them beside a control that creates a *new* group invites the reader
                     to think they apply to all of them.

                     Drawn in this view rather than returned by getHeaderActions(), so Filament
                     does not render a second copy of each in the page header. They stay
                     registered there, because that is what lets a click resolve.
                --}}
                <div class="flex shrink-0 items-center gap-1.5">
                    @if ($this->joinCode())
                        {{-- The code a teacher reads out, with a copy button beside it: it is
                             the one control here worth one click, because transcribing eight
                             characters from a screen onto a whiteboard is exactly the kind of
                             small friction that makes people not bother. --}}
                        <span
                            class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 dark:bg-white/10"
                            x-data="{ copied: false }"
                        >
                            <span class="font-mono text-xs text-gray-700 dark:text-gray-200">
                                {{ $this->joinCode() }}
                            </span>

                            <button
                                type="button"
                                x-on:click="navigator.clipboard.writeText('{{ $this->joinCode() }}'); copied = true; setTimeout(() => copied = false, 1500)"
                                x-bind:aria-label="copied ? 'Code copied' : 'Copy join code'"
                                class="rounded p-0.5 text-gray-500 transition hover:bg-gray-200 hover:text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-100"
                            >
                                <x-filament::icon
                                    icon="heroicon-m-clipboard-document"
                                    x-show="! copied"
                                    class="h-3.5 w-3.5"
                                />

                                <x-filament::icon
                                    icon="heroicon-m-check"
                                    x-show="copied"
                                    x-cloak
                                    class="h-3.5 w-3.5 text-success-600"
                                />
                            </button>

                            <span
                                x-show="copied"
                                x-cloak
                                x-transition.opacity
                                class="text-xs text-success-600"
                            >
                                Copied
                            </span>
                        </span>
                    @endif

                    {{ $this->groupControlsActionGroup() }}
                </div>
            </div>

            {{-- Transcript and roster --}}
            <div class="flex min-h-0 flex-1">
                <div
                    class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4"
                    role="log"
                    aria-label="Messages in {{ $group->name }}"
                    aria-live="polite"
                >
                    @forelse ($this->transcript() as $message)
                        @php($mine = $message->sender_id === auth()->id())

                        @if ($message->isSystem())
                            {{-- Joins and leaves: status, not conversation. --}}
                            <p class="text-center text-xs italic text-gray-400 dark:text-gray-500">
                                {{ $message->body }}
                            </p>
                        @else
                            <div @class([
                                'flex',
                                'justify-end' => $mine,
                                'justify-start' => ! $mine,
                            ])>
                                <div @class([
                                    'max-w-[80%] rounded-2xl px-3.5 py-2',
                                    'bg-gray-900 text-white dark:bg-white dark:text-gray-950' => $mine,
                                    'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-gray-100' => ! $mine,
                                ])>
                                    @unless ($mine)
                                        <p class="mb-0.5 text-xs font-semibold opacity-70">
                                            {{ $message->sender?->full_name ?? 'Unknown' }}
                                        </p>
                                    @endunless

                                    @if ($message->hasAttachment())
                                        <a
                                            href="{{ route('group-chat.files.download', ['message' => $message->id]) }}"
                                            class="flex items-center gap-2 rounded-lg border border-white/20 px-2.5 py-1.5 text-sm hover:bg-white/10"
                                        >
                                            <x-filament::icon
                                                :icon="$message->type === \App\Enums\GroupMessageType::Image ? 'heroicon-m-photo' : 'heroicon-m-document'"
                                                class="h-4 w-4"
                                            />
                                            <span class="truncate">{{ $message->file_name }}</span>
                                            <span class="text-xs opacity-70">{{ \App\Support\Bytes::format((int) $message->file_size) }}</span>
                                        </a>
                                    @endif

                                    @if ($message->body !== '')
                                        <p class="text-sm whitespace-pre-wrap break-words">{{ $message->body }}</p>
                                    @endif

                                    <p class="mt-0.5 text-right text-[10px] opacity-60">
                                        {{ $message->created_at->format('H:i') }}
                                    </p>
                                </div>
                            </div>
                        @endif
                    @empty
                        <p class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            Nothing here yet. Send the first message.
                        </p>
                    @endforelse
                </div>

                {{-- Roster --}}
                <aside class="hidden w-56 shrink-0 overflow-y-auto border-l border-gray-200 p-3 lg:block dark:border-white/10">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Members
                    </h3>

                    <ul role="list" class="mt-2 space-y-1">
                        @foreach ($this->roster() as $member)
                            <li class="flex items-center justify-between gap-2">
                                <span class="min-w-0 truncate text-sm text-gray-700 dark:text-gray-200">
                                    {{ $member->full_name }}
                                </span>

                                @if ($member->id === $group->created_by_id)
                                    <span
                                        class="shrink-0 text-xs text-gray-400 dark:text-gray-500"
                                        title="Created this group"
                                    >
                                        owner
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </aside>
            </div>

            {{-- Typing indicator --}}
            <p
                data-typing-indicator
                data-typing-user-id="{{ $this->typingContext()['userId'] }}"
                data-typing-ttl="{{ $this->typingContext()['ttlSeconds'] }}"
                class="h-5 px-4 text-xs text-gray-500 empty:hidden dark:text-gray-400"
                aria-live="polite"
            ></p>

            {{-- Composer --}}
            <form
                wire:submit="sendMessage"
                class="border-t border-gray-200 p-3 dark:border-white/10"
            >
                <label for="chat-draft" class="sr-only">Message</label>

                <textarea
                    id="chat-draft"
                    wire:model="draft"
                    data-chat-draft
                    data-typing-enabled="1"
                    rows="2"
                    maxlength="{{ config('lab.group_chats.max_message_length') }}"
                    placeholder="Write a message&hellip;"
                    class="w-full resize-none rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                ></textarea>

                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <label class="flex cursor-pointer items-center gap-1.5 text-xs text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                        <x-filament::icon icon="heroicon-m-paper-clip" class="h-4 w-4" />
                        Attach
                        <input
                            type="file"
                            wire:model="pendingFiles"
                            multiple
                            class="sr-only"
                            accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.gif,.zip,.rar"
                        />
                    </label>

                    <button
                        type="submit"
                        class="flex items-center gap-1.5 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white transition hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:opacity-50 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                        wire:loading.attr="disabled"
                        wire:target="sendMessage"
                    >
                        <x-filament::icon icon="heroicon-m-paper-airplane" class="h-4 w-4" />
                        Send
                    </button>
                </div>

                @if ($this->pendingFiles)
                    <ul role="list" class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($this->pendingFiles as $pending)
                            <li class="rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                {{-- A Livewire temporary upload has getFilename() but not
                                     Symfony's client getters, and a plain string can turn up
                                     here too, so this asks rather than assumes. --}}
                                @if (is_object($pending) && method_exists($pending, 'getFilename'))
                                    {{ $pending->getFilename() }}
                                @elseif (is_object($pending) && method_exists($pending, 'getClientOriginalName'))
                                    {{ $pending->getClientOriginalName() }}
                                @else
                                    {{ (string) $pending }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </form>
        @endif
    </div>

</div>
</x-filament-panels::page>
