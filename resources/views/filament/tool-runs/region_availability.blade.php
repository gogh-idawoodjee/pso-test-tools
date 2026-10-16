@php
    $dataset = $run->scope;
    $counts = $run->summary['counts'] ?? [];
    $week = $run->summary['week'] ?? null;
    $hasPage = filled($run->result['html'] ?? null);
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-2 text-sm font-medium">
            <x-filament::badge color="gray">{{ (int) ($counts['techs'] ?? 0) }} technicians</x-filament::badge>
            <x-filament::badge color="gray">{{ (int) ($counts['shifts'] ?? 0) }} shifts</x-filament::badge>
            <x-filament::badge color="warning">{{ (int) ($counts['techs_with_changes'] ?? 0) }} with changes</x-filament::badge>
            @if ($week)
                <span class="text-gray-600 dark:text-gray-400">Week of {{ $week }}</span>
            @endif
        </div>
        @if ($hasPage)
        <div class="flex gap-2">
            <x-filament::button tag="a" href="{{ route('tool-runs.page', [$dataset, $run]) }}" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square" color="gray" size="sm">Open full page</x-filament::button>
            <x-filament::button tag="a" href="{{ route('tool-runs.download-html', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download HTML</x-filament::button>
            <x-filament::button tag="a" href="{{ route('tool-runs.download-json', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download JSON</x-filament::button>
        </div>
        @endif
    </div>
</x-filament::section>

{{-- The viewer's own page, as region_availability.py rendered it: week grid, one-day view, region pivot.
     Sandboxed: no same-origin, so its scripts cannot reach the app. --}}
{{-- Its Previous and Next week links name sibling files that do not exist here; the page posts the week
     instead (see the template) and the app runs the viewer again on the same LOAD for that week. --}}
@if ($hasPage)
<div
    x-data
    x-on:message.window="if ($event.source === $refs.page.contentWindow && $event.data?.type === 'pso-tools.region-availability.week') $wire.runWeek(String($event.data.week))"
>
<iframe
    x-ref="page"
    src="{{ route('tool-runs.page', [$dataset, $run]) }}"
    title="Region availability"
    sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"
    class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    style="height: calc(100vh - 10rem); min-height: 600px;"
></iframe>
</div>
@else
<p class="text-sm text-gray-600 dark:text-gray-400">The page is not available for this run.</p>
@endif
