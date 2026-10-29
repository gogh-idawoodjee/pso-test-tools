@php
    $routeParams = \App\Http\Controllers\ToolRunPageController::params($run->scope, $run);
    $summary = $run->summary ?? [];
    $line = $summary['line'] ?? null;
    $hasPage = filled($run->result['html'] ?? null);
    $hasCsv = is_string($run->result['csv'] ?? null);
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-2">
            @if (filled($line))
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $line }}</p>
            @endif
            <div class="flex flex-wrap gap-2 text-sm font-medium">
                <x-filament::badge color="gray">{{ number_format((int) ($summary['leg_count'] ?? 0)) }} legs</x-filament::badge>
                <x-filament::badge color="warning">{{ number_format((int) ($summary['review_count'] ?? 0)) }} to review</x-filament::badge>
                <x-filament::badge color="danger">{{ number_format((int) ($summary['missing_count'] ?? 0)) }} missing a travel time</x-filament::badge>
            </div>
        </div>
        @if ($hasPage)
        <div class="flex flex-wrap gap-2">
            @if ($hasCsv)
                <x-filament::button tag="a" href="{{ route('tool-runs.page', $routeParams) }}" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square" color="gray" size="sm">Open full page</x-filament::button>
                <x-filament::button tag="a" href="{{ route('tool-runs.download-csv', $routeParams) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download CSV</x-filament::button>
            @endif
            <x-filament::button tag="a" href="{{ route('tool-runs.download-json', $routeParams) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download JSON</x-filament::button>
        </div>
        @endif
    </div>
</x-filament::section>

{{-- The page plan_travels.py rendered. Sandboxed with no same-origin, so its scripts cannot reach the app;
     popups may leave the sandbox so "Check in PSO" opens a working Travel Analyzer in a new tab. --}}
@if ($hasPage)
<iframe
    src="{{ route('tool-runs.page', $routeParams) }}"
    title="Plan travels"
    sandbox="allow-scripts allow-downloads allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
    class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    style="height: calc(100vh - 10rem); min-height: 600px;"
></iframe>
@else
<p class="text-sm text-gray-600 dark:text-gray-400">The plan travels page is not available for this run.</p>
@endif
