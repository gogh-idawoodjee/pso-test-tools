@php
    $routeParams = \App\Http\Controllers\ToolRunPageController::params($run->scope, $run);
    $summary = $run->summary ?? [];
    $hasPage = filled($run->result['html'] ?? null);
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-2">
            @if (filled($summary['line'] ?? null))
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $summary['line'] }}</p>
            @endif
            <div class="flex flex-wrap gap-2 text-sm font-medium">
                <x-filament::badge color="gray">{{ number_format((int) ($summary['runs'] ?? 0)) }} runs</x-filament::badge>
                <x-filament::badge color="warning">Flagged {{ number_format((int) ($summary['baseline_flagged'] ?? 0)) }} to {{ number_format((int) ($summary['final_flagged'] ?? 0)) }}</x-filament::badge>
                <x-filament::badge color="success">{{ number_format((int) ($summary['fixed'] ?? 0)) }} fixed</x-filament::badge>
                <x-filament::badge color="danger">{{ number_format((int) ($summary['newly_broken'] ?? 0)) }} newly broken</x-filament::badge>
            </div>
        </div>
        @if ($hasPage)
            <div class="flex flex-wrap gap-2">
                <x-filament::button tag="a" href="{{ route('tool-runs.page', $routeParams) }}" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square" color="gray" size="sm">Open full page</x-filament::button>
                <x-filament::button tag="a" href="{{ route('tool-runs.download-html', $routeParams) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download HTML</x-filament::button>
            </div>
        @endif
    </div>
</x-filament::section>

{{-- The page travel_tuning.py rendered. Sandboxed with no same-origin, so its scripts cannot reach the app;
     it loads Chart.js and Leaflet from cdnjs and the map tiles from their providers. --}}
@if ($hasPage)
<iframe
    src="{{ route('tool-runs.page', $routeParams) }}"
    title="Travel tuning"
    sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"
    class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    style="height: calc(100vh - 10rem); min-height: 600px;"
></iframe>
@else
<p class="text-sm text-gray-600 dark:text-gray-400">The travel tuning page is not available for this run.</p>
@endif
