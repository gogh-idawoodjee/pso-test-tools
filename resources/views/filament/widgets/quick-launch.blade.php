<x-filament-widgets::widget>
    <x-filament::section
        heading="Quick Launch"
        description="Jump straight into a tool or dataset."
    >
        <style>
            .quick-launch-tile {
                border-color: color-mix(in srgb, var(--accent) 25%, transparent);
            }

            .quick-launch-tile:hover {
                border-color: var(--accent);
                background-color: color-mix(in srgb, var(--accent) 10%, transparent);
            }

            .quick-launch-icon {
                color: var(--accent);
            }
        </style>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($this->getVisibleGroups() as $group)
                <div class="space-y-3" style="--accent: {{ $group['accent'] }}">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full" style="background-color: var(--accent)"></span>

                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $group['label'] }}
                        </h3>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        @foreach ($group['items'] as $item)
                            <a
                                href="{{ $item['url'] }}"
                                class="quick-launch-tile group flex items-center gap-2.5 rounded-lg border px-3 py-2 text-sm font-medium text-gray-700 transition-colors dark:text-gray-200"
                            >
                                <x-filament::icon :icon="$item['icon']" class="quick-launch-icon h-4 w-4 shrink-0" />

                                <span class="truncate">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
