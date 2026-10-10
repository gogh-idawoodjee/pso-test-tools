<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Choose a dataset</x-slot>
        <x-slot name="description">The tool runs against exports uploaded to a dataset. Pick one to open its Runs tab.</x-slot>

        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($this->datasets() as $dataset)
                <li class="flex items-center justify-between py-3 text-sm">
                    <span>
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $dataset['name'] }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $dataset['environment'] }}</span>
                    </span>
                    <a href="{{ $dataset['url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Open Runs</a>
                </li>
            @empty
                <li class="py-3 text-sm text-gray-500">You have no datasets yet.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
