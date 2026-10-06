{{--
    Student dashboard header: greeting, storage, quick links and recent activity.

    Server-rendered, not fetched after load. A lab machine on a slow link should
    still show a usable page on first paint, and the content is useful to a screen
    reader without a live region.

    Headings and landmarks are real, so the structure survives without Filament's
    visual styling.
--}}
<x-filament-widgets::widget>
    <div class="flex flex-col gap-6">
        <section aria-labelledby="welcome-heading">
            <h2 id="welcome-heading" class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ $greeting }}, {{ $name }}
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ $storage }}
            </p>
        </section>

        <nav aria-labelledby="quick-actions-heading">
            <h3 id="quick-actions-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Quick actions
            </h3>

            <ul role="list" class="mt-3 flex flex-wrap gap-2">
                @foreach ($actions as $action)
                    <li>
                        <a
                            href="{{ $action['url'] }}"
                            class="fi-btn fi-size-md fi-color fi-color-gray"
                        >
                            {{-- Decorative: the link text already says where it goes. --}}
                            <x-filament::icon :icon="$action['icon']" aria-hidden="true" />
                            <span>{{ $action['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <section aria-labelledby="recent-activity-heading">
            <h3 id="recent-activity-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Recent activity
            </h3>

            @if ($activity->isEmpty())
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                    Nothing yet. Uploading a file or joining a class will show up here.
                </p>
            @else
                <ul role="list" class="mt-3 divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($activity as $item)
                        <li class="flex items-center gap-x-3 py-2">
                            <x-filament::icon
                                :icon="$item['kind'] === 'file'
                                    ? 'heroicon-o-document'
                                    : 'heroicon-o-envelope'"
                                aria-hidden="true"
                                class="text-gray-400"
                            />

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $item['label'] }}
                                </span>
                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ $item['detail'] }}
                                </span>
                            </span>

                            {{-- Machine-readable absolute time; CSS renders the relative form. --}}
                            <time
                                datetime="{{ $item['when']->toIso8601String() }}"
                                title="{{ $item['when']->toDayDateTimeString() }}"
                                class="shrink-0 text-xs text-gray-500 dark:text-gray-400"
                            >
                                {{ $item['when']->diffForHumans() }}
                            </time>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-filament-widgets::widget>