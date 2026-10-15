<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        @foreach ($this->sections() as $section)
            <x-filament::section style="--accent: {{ $section['accent'] }}">
                <x-slot name="heading">
                    <span class="inline-flex items-center gap-2"><span class="h-2 w-2 rounded-full" style="background-color: var(--accent)"></span>{{ $section['label'] }}</span>
                </x-slot>
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($section['items'] as $item)
                        <li class="py-3">
                            <a href="{{ $item['url'] }}" class="block rounded-lg px-2 py-1 -mx-2 hover:bg-gray-50 dark:hover:bg-white/5">
                                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $item['label'] }}</span>
                                <span class="block text-sm text-gray-600 dark:text-gray-300">{{ $item['question'] }}</span>
                                @if ($item['needs'])
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $item['needs'] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
