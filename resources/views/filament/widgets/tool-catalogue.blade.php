<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        @foreach ($this->sections() as $section)
            <x-filament::section style="--accent: {{ $section['accent'] }}">
                <x-slot name="heading">
                    <span class="inline-flex items-center gap-2"><span class="h-2 w-2 rounded-full" style="background-color: var(--accent)"></span>{{ $section['label'] }}</span>
                </x-slot>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($section['items'] as $item)
                        <a href="{{ $item['url'] }}" data-tool-tile class="flex gap-3 rounded-xl bg-white p-3 ring-1 ring-gray-950/5 outline-none transition hover:ring-gray-950/15 focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:bg-white/5 dark:ring-white/10 dark:hover:ring-white/20">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg" style="background: color-mix(in srgb, var(--accent) 14%, transparent); color: var(--accent)">
                                <x-filament::icon :icon="$item['icon']" class="size-5" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $item['label'] }}</span>
                                <span class="block text-sm text-gray-600 dark:text-gray-400">{{ $item['question'] }}</span>
                                @if ($item['needs'])
                                    <span class="block text-xs text-gray-500">{{ $item['needs'] }}</span>
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
