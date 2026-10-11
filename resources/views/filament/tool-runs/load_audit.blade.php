@php
    $dataset = $run->scope;
    $counts = $run->summary['counts'] ?? $run->result['counts'] ?? ['red' => 0, 'amber' => 0, 'green' => 0];
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex gap-2 text-sm font-medium">
            <x-filament::badge color="danger">{{ $counts['red'] }} red</x-filament::badge>
            <x-filament::badge color="warning">{{ $counts['amber'] }} amber</x-filament::badge>
            <x-filament::badge color="success">{{ $counts['green'] }} green</x-filament::badge>
        </div>
        <div class="flex gap-2">
            <x-filament::button tag="a" href="{{ route('load-audit.download-html', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download HTML</x-filament::button>
            <x-filament::button tag="a" href="{{ route('load-audit.download-findings', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download findings JSON</x-filament::button>
        </div>
    </div>
</x-filament::section>

{{-- The audit's own page, as audit_load.py rendered it. Sandboxed: no same-origin, so its scripts cannot reach the app. --}}
<iframe
    src="{{ route('load-audit.dashboard', [$dataset, $run]) }}"
    title="Load and configuration health audit"
    sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"
    class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    style="height: calc(100vh - 14rem); min-height: 600px;"
></iframe>
