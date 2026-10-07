{{--
    Live Screens — a grid of lab PC screens, refreshed in place.

    Custom Blade rather than a Filament schema because the frames are not Livewire
    state. Each <img> points at a policy-gated URL and re-requests it on a timer;
    routing that through Livewire would rebuild the whole page every second.

    Nothing here is given a screen's content to work with. The server decides which
    tiles exist (ScreenAccessService) and the frame endpoint independently decides
    whether to answer each request, so a tile that appears is a tile whose bytes the
    teacher is entitled to.

    Accessibility notes:
      - Tiles are <button>s, not clickable divs, so keyboard and screen-reader users
        can open the solo view without a mouse.
      - The grid is a real list, and each tile names its machine and its occupant.
      - Status is carried in text ("live", "no signal"), not only in a colour, so it
        survives a colourblind reader and a screen reader.
      - The solo overlay is a <dialog>, so focus is trapped and Escape closes it
        without hand-written focus juggling.
--}}
<div class="flex flex-col gap-6">
    @unless ($enabled)
        <x-filament::section>
            <x-slot name="heading">Live Screens is switched off</x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                <code>LAB_MONITORING_ENABLED</code> is off, so enrolled clients are refusing to
                upload. Set it back to <code>true</code> in <code>.env</code> to resume.
            </p>
        </x-filament::section>
    @endunless

    <section aria-labelledby="live-screens-heading" class="pt-10">
        {{--
            The filter chips.

            With thirty machines the question this page gets opened for is usually "which
            one needs looking at", and answering it meant reading thirty badges. Counts are
            taken before filtering, so a selected chip still shows the size of the whole
            lab and the other chips stay a way back out.

            Rendered as real <button>s with aria-pressed rather than as links, since these
            change state in place rather than navigate.
        --}}
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter machines by client status">
            @foreach ($filters as $key => $chip)
                <button
                    type="button"
                    wire:click="$set('filter', '{{ $key }}')"
                    aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                    @class([
                        'rounded-md border px-3 py-1.5 text-sm font-medium transition',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',

                        // Solid when selected, outlined when not - the one piece of state
                        // the page needs to be readable at a glance.
                        'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-950' => $filter === $key,
                        'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $filter !== $key,
                    ])
                >
                    {{ $chip['label'] }}
                    <span class="opacity-70">({{ $chip['count'] }})</span>
                </button>
            @endforeach
        </div>

        <div class="mt-6 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <div>
                <h2 id="live-screens-heading" class="text-lg font-semibold text-gray-950 dark:text-white">
                    Lab screens
                </h2>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Watch what each machine in {{ $scopeLabel }} is showing
                </p>
            </div>

            <p class="text-xs text-gray-500 dark:text-gray-400">
                Select a machine to open its screen
            </p>
        </div>

        @if (empty($pcs))
            <x-filament::section class="mt-4">
                <x-slot name="heading">Nothing to watch</x-slot>

                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{--
                        Deliberately no command here. An administrator enrols from the
                        Computers list; a teacher is told to ask one. Pointing either at
                        a shell they were not going to use is the gap this whole flow
                        closed.
                    --}}
                    @if ($isAdmin)
                        No lab PCs are enrolled yet. Go to
                        <a href="{{ \App\Filament\Resources\Pcs\PcResource::getUrl('index') }}" class="underline">Computers</a>
                        and use <strong>Enrol client</strong> on the machine you want to start
                        watching — it gives you a code. Then double-click <code>install.cmd</code>
                        on that machine and paste the code in.
                    @else
                        None of the PCs used by your class have the Lab Client installed yet.
                        Ask an administrator to enrol them.
                    @endif
                </p>
            </x-filament::section>
        @else
            {{--
                No hint here. One already sits under the heading, and a second copy of it
                directly above the grid was the single ugliest thing on the page - the same
                sentence twice, three lines apart.
            --}}
            {{--
                data-pc-id on each tile is what monitoring.js keys its registry off, and
                data-frame-url is the policy-gated endpoint the overlay reads from. Both
                are written from values the server cast or generated, never from a request,
                so neither can carry anything but a number and a URL of our own making.

                wire:poll refreshes these tiles without touching a single frame. The tile
                no longer holds an image, so the page's ongoing cost is one small Livewire
                response every few seconds regardless of how many machines are enrolled —
                previously every visible tile fetched a screenshot on its own timer.
            --}}
            <ul
                role="list"
                data-live-screen-tiles
                data-interval="{{ $interval }}"
                wire:poll.5s
                {{--
                    Narrow and tall rather than wide and short. A tile is three short
                    strings stacked, so width buys nothing and height is what makes the
                    stack readable at a glance; eight across also fits a thirty-machine lab
                    on one screen, which is the point of the page.

                    No 2xl: prefix. This stylesheet's theme defines breakpoints only up to
                    xl, so `2xl:grid-cols-8` is silently dropped — the rule is never
                    generated and the grid quietly stays at four columns with nothing in the
                    page to say why.
                --}}
                class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
            >
                @foreach ($pcs as $pc)
                    <li>
                        {{--
                            No <img> in the tile. A minimised tile answers "which machine is
                            this and who is sitting at it", and an image 1280px wide is
                            neither of those things. It also cost about 100 KB every interval
                            per machine: forty enrolled PCs were pulling roughly 4 MB/s to
                            render pictures nobody had opened. The frame is fetched only
                            while the overlay for that machine is actually on screen.
                        --}}
                        <button
                            type="button"
                            class="live-screen-tile group relative flex min-h-24 w-full flex-col rounded-lg border border-gray-200 bg-white px-4 py-3 transition hover:border-primary-500 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
                            data-pc-id="{{ $pc['id'] }}"
                            data-frame-url="{{ $pc['frameUrl'] }}"
                            aria-label="Open {{ $pc['name'] }} full screen{{ $pc['student'] ? ', used by '.$pc['student'] : '' }}"
                        >
                            {{--
                                A coloured top edge on a machine that has stopped reporting,
                                so the two or three tiles worth looking at can be found
                                without reading any badges at all.

                                A drawn bar rather than a differently-coloured top border.
                                Expressing that as `border-2 border-t-amber-500
                                border-r-gray-200 ...` needs a per-side colour on every other
                                edge to stop `border-2` painting them all in the default
                                border colour, and that came out as a heavy dark frame round
                                every tile - including the ones with nothing to report.
                                One absolutely positioned element cannot do that.
                            --}}
                            @if ($pc['enrolled'] && ! $pc['live'])
                                <span
                                    aria-hidden="true"
                                    class="absolute inset-x-0 top-0 h-1 rounded-t-lg bg-amber-500"
                                ></span>
                            @endif
                            {{--
                                Upper-left and upper-right, inset from the edges rather
                                than flush into them. Flush corners collide with the
                                rounded border and the badge's own rounding, and a tile
                                that wide and short has very little room to spare - an
                                inset of three units keeps both clear of the edge.

                                Decorative: the machine's name sits in the middle of the
                                tile as text, and aria-hidden keeps an unlabelled graphic
                                out of a screen reader's narration.
                            --}}
                            <span
                                aria-hidden="true"
                                class="absolute left-3 top-3 flex h-8 w-8 items-center justify-center rounded-md bg-gray-100 text-gray-500 transition group-hover:bg-white dark:bg-white/10 dark:text-gray-400 dark:group-hover:bg-white/10"
                            >
                                <x-filament::icon icon="heroicon-o-computer-desktop" class="h-4 w-4" />
                            </span>

                            {{--
                                data-live-screen-name / -student carry the two strings the
                                overlay needs. Exposed as attributes rather than scraped from
                                the Tailwind classes on these elements: `.font-medium` and
                                `.text-gray-500` are styling, and any future element wearing
                                either above these would silently become the label for a
                                different machine.

                                The person icon sits outside -student on purpose: the overlay
                                reads that element's textContent to decide whether a machine
                                is occupied, and anything else inside it would end up
                                compared against the string "Free".
                            --}}
                            {{-- The name takes the slack, so it sits on the tile's centre line. --}}
                            <span class="flex w-full flex-1 flex-col items-center justify-center px-16 pt-6">
                                <span
                                    data-live-screen-name
                                    class="block w-full truncate text-sm font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ $pc['name'] }}
                                </span>
                            </span>

                            {{-- And the occupant sits at the bottom, on every tile. --}}
                            <span class="mt-1 flex w-full items-center justify-center gap-1 text-xs">
                                @if ($pc['student'])
                                    <x-filament::icon
                                        icon="heroicon-o-user"
                                        aria-hidden="true"
                                        class="h-3.5 w-3.5 shrink-0 text-gray-400 dark:text-gray-500"
                                    />
                                @endif

                                <span
                                    data-live-screen-student
                                    @class([
                                        'truncate',
                                        'font-medium text-gray-700 dark:text-gray-300' => $pc['student'],
                                        'text-gray-400 dark:text-gray-500' => ! $pc['student'],
                                    ])
                                >
                                    {{ $pc['student'] ?? 'Free' }}
                                </span>
                            </span>

                            {{--
                                The smallest badge Filament offers.

                                "not enrolled" is by far the widest label, and at medium
                                size it reached a third of the way across a tile - far
                                enough to sit on top of the machine's name, which is
                                centred underneath it. The name has to be padded clear of
                                both corners anyway, and a narrower badge means less of
                                that padding on every tile.

                                Filament's own badge, so the colour comes from its palette.
                                Hand-written `bg-success-500` and `text-success-700` were
                                both classes that do not exist in this stylesheet — the app is
                                built greyscale — so a live machine's badge rendered in plain
                                body text. Nothing looked broken; it just looked like
                                everything was the same.

                                Rendered by the server and refreshed by the poll above, which
                                is why monitoring.js no longer touches it. Two renderers of
                                the same badge meant the JavaScript's colour classes quietly
                                overwrote these on the first frame that arrived.

                                Status is a word as well as a colour: "live" is announced by a
                                screen reader, and is legible to a colourblind reader.
                            --}}
                            <x-filament::badge
                                :color="$pc['color']"
                                size="sm"
                                class="absolute right-2.5 top-2.5 uppercase tracking-wide"
                            >
                                {{ $pc['statusLabel'] }}
                            </x-filament::badge>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{--
        Administrator panels, below the machines and collapsed.

        They used to sit above the grid, which put four headings and a table of settings
        between the reader and the thing they came for. A page called Live Screens should
        open on the machines. These are diagnostics - useful when something is wrong,
        noise when nothing is - so they stay available without competing for attention.

        A <details> rather than a toggle button: it needs no JavaScript, it survives a
        Livewire morph with its open state intact, and it is announced correctly by a
        screen reader as a disclosure rather than as a mysterious control.
    --}}
    @if ($isAdmin)
        <details class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10">
            <summary class="cursor-pointer text-sm font-medium text-gray-700 dark:text-gray-300">
                Administrator details
                @if (! empty($clientStatus))
                    <span class="ml-1 font-normal text-gray-500 dark:text-gray-400">
                        &middot; {{ collect($clientStatus)->sum(fn (array $group): int => count($group['machines'])) }} machine(s) need attention
                    </span>
                @endif
            </summary>

            @if (! empty($clientStatus))
                <section aria-labelledby="client-status-heading" class="mt-4">
                    <h3 id="client-status-heading" class="text-sm font-semibold text-gray-950 dark:text-white">
                        Lab Clients needing attention
                    </h3>

                    {{--
                        Each group is a different problem with a different fix, which is why
                        they are listed separately rather than as one count - a client that
                        never checked in needs its code checked, one that went silent needs
                        the machine looked at.

                        The Enrol client action lives on the PC list, not here: this page is
                        about watching screens, and a button here would invite enrolling
                        machines from a page whose purpose is viewing them.
                    --}}
                    <ul role="list" class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($clientStatus as $group)
                            <li class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                <p class="text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $group['label'] }}
                                    <span class="font-normal text-gray-500 dark:text-gray-400">
                                        ({{ count($group['machines']) }})
                                    </span>
                                </p>

                                {{-- The fix, stated rather than left for the reader to infer. --}}
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                    {{ $group['guidance'] }}
                                </p>

                                <p class="mt-2 font-mono text-xs text-gray-700 dark:text-gray-300">
                                    {{ implode(', ', $group['machines']) }}
                                </p>
                            </li>
                        @endforeach
                    </ul>

                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
To enrol or revoke a client, use
                        <a href="{{ \App\Filament\Resources\Pcs\PcResource::getUrl('index') }}" class="underline">Computers</a>,
                        then double-click <code>install.cmd</code> on the machine and paste the code
                        it shows you.
                    </p>
                </section>
            @endif

            <section aria-labelledby="capture-settings-heading" class="mt-4">
                <h3 id="capture-settings-heading" class="text-sm font-semibold text-gray-950 dark:text-white">
                    Capture settings
                </h3>

                {{--
                    Read-only. These live in .env and are handed to each client by the
                    server, so they are shown rather than edited here - a control that
                    appeared editable but wrote nothing would be worse than no control.
                --}}
                <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-4">
                    @foreach ($captureSettings as $label => $value)
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ ucfirst(str_replace('_', ' ', $label)) }}</dt>
                            <dd class="text-sm text-gray-950 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </details>
    @endif

    {{--
        wire:ignore is load-bearing, not an optimisation.

        The grid above polls every five seconds to refresh each machine's status. A poll
        re-renders this whole component and morphs the result, and <dialog>'s open state is
        a DOM property rather than markup - so morphing the dialog element drops `open` and
        the overlay silently closes itself a few seconds after it was opened. The symptom
        is "it opens, then vanishes on its own", and it reproduces on a timer rather than on
        a click, which is what finally identified it.

        wire:ignore tells Livewire to leave this subtree alone entirely. Everything inside
        is written by monitoring.js - the label, the frame, the placeholder - so there is
        nothing for a re-render to usefully update anyway.
    --}}
    <dialog
        data-live-screen-solo
        wire:ignore
        class="m-auto h-full max-h-none w-full max-w-none bg-black/90 p-0 backdrop:bg-black/90"
        aria-label="Full screen view"
    >
        <div class="flex h-full flex-col">
            <div class="flex items-center justify-between gap-4 p-3 text-white">
                <p data-live-solo-label class="truncate text-sm font-medium"></p>

                <button
                    type="button"
                    data-live-solo-close
                    class="fi-btn fi-size-sm fi-color fi-color-gray shrink-0"
                >
                    Close
                </button>
            </div>

            <div class="relative min-h-0 flex-1 p-3 pb-6">
                {{-- Populated by monitoring.js from the same policy-gated URL. --}}
                <img data-live-solo-frame alt="" class="h-full w-full object-contain" decoding="async">

                {{--
                    The overlay's own placeholder. The tile no longer holds an image, so
                    there is nothing there to fall back to — without this a machine that
                    has stopped reporting would leave the last frame it fetched frozen on
                    screen, which is precisely how a dead machine came to look like a live
                    one. Sits above the image and is hidden the moment a frame arrives.
                --}}
                <p
                    data-live-solo-placeholder
                    class="absolute inset-0 flex items-center justify-center p-3 text-center text-sm text-white/70"
                >
                    No signal — this machine has not sent a current frame.
                </p>
            </div>
        </div>
    </dialog>
</div>