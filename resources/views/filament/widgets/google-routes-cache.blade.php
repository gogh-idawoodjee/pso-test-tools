<x-filament-widgets::widget>
    <x-filament::section heading="Google Routes cache">
        @php($summary = $this->summary())
        @if ($summary === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">pso-services could not be reached. The cache is still used by Plan travels and the Travel Analyzer.</p>
        @else
            <div class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                <p><span class="font-medium text-gray-950 dark:text-white">{{ $summary['rows'] }}</span> routes cached</p>
                <p>{{ $summary['sources'] }}</p>
                <p>{{ $summary['added'] }}</p>
                <p>{{ $summary['newest'] }}</p>
                @if ($summary['modes'])
                    <p>{{ $summary['modes'] }}</p>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
