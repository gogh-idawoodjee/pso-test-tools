@php
    $dataset = $run->scope;
    $counts = $run->summary['counts'] ?? [];
    $line = $run->summary['line'] ?? null;
    $hasPage = filled($run->result['html'] ?? null);
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-2">
            @if (filled($line))
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $line }}</p>
            @endif
            <div class="flex gap-2 text-sm font-medium">
                <x-filament::badge color="danger">{{ $counts['red'] ?? 0 }} red</x-filament::badge>
                <x-filament::badge color="warning">{{ $counts['amber'] ?? 0 }} amber</x-filament::badge>
                <x-filament::badge color="success">{{ $counts['green'] ?? 0 }} green</x-filament::badge>
            </div>
        </div>
        @if ($hasPage)
        <div class="flex flex-wrap gap-2">
            <x-filament::button tag="a" href="{{ route('tool-runs.page', [$dataset, $run]) }}" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square" color="gray" size="sm">Open full page</x-filament::button>
            <x-filament::button tag="a" href="{{ route('tool-runs.download-html', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download HTML</x-filament::button>
            <x-filament::button tag="a" href="{{ route('tool-runs.download-json', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download JSON</x-filament::button>
            <x-filament::button tag="a" href="{{ route('tool-runs.download-booking-csv', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download booking CSV</x-filament::button>
            <x-filament::button tag="a" href="{{ route('tool-runs.download-history-csv', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download history CSV</x-filament::button>
        </div>
        @endif
    </div>
</x-filament::section>

{{-- The trace page, as trace_activity.py rendered it. Sandboxed: no same-origin, so its scripts cannot reach the app. --}}
@if ($hasPage)
<iframe
    src="{{ route('tool-runs.page', [$dataset, $run]) }}"
    title="Trace an activity"
    sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"
    class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    style="height: calc(100vh - 10rem); min-height: 600px;"
></iframe>
@else
<p class="text-sm text-gray-600 dark:text-gray-400">The trace page is not available for this run.</p>
@endif
