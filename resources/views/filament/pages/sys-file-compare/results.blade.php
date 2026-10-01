@php
    $bannerState = $summary['bannerState'];
    $environments = $summary['environments'];
    $baseline = $summary['baseline'];
    $others = array_values(array_filter($environments, static fn (string $name): bool => $name !== $baseline));
    $totalCompared = $summary['totalCompared'];
@endphp

<div class="space-y-6">
    {{-- Version banner first: the one thing that must not be missed. --}}
    <div
        @class([
            'rounded-xl p-5 text-white',
            'bg-danger-600 ring-4 ring-danger-900' => $bannerState === 'mismatch',
            'bg-success-600' => $bannerState === 'match',
            'bg-warning-400 text-gray-900' => $bannerState === 'unknown',
        ])
        role="alert"
    >
        <p class="text-xl font-bold">{{ $summary['bannerTitle'] }}</p>
        @if ($summary['bannerDetail'] !== '')
            <p class="mt-1 text-sm">{{ $summary['bannerDetail'] }}</p>
        @endif
    </div>

    @foreach ($summary['notes'] as $note)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $note }}</p>
        </x-filament::section>
    @endforeach

    <x-filament::section>
        <x-slot name="heading">PSO version by environment</x-slot>
        <x-slot name="description">From the System_Version table; all times are UTC.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left divide-y divide-gray-200 dark:divide-white/10">
                <thead>
                    <tr class="text-gray-600 dark:text-gray-400">
                        <th class="py-2 pe-4 font-semibold">Environment</th>
                        <th class="py-2 pe-4 font-semibold">Current version</th>
                        <th class="py-2 pe-4 font-semibold">Status</th>
                        <th class="py-2 pe-4 font-semibold">Last upgrade</th>
                        <th class="py-2 pe-4 font-semibold">Upgraded from → to</th>
                        <th class="py-2 pe-4 font-semibold">Last patch</th>
                        <th class="py-2 font-semibold">Days since upgrade</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($summary['versions'] as $version)
                        <tr>
                            <td class="py-2 pe-4 font-medium">{{ $version['environment'] }}</td>
                            <td class="py-2 pe-4">{{ $version['current'] !== '' ? $version['current'] : '—' }}</td>
                            <td class="py-2 pe-4">
                                <x-filament::badge :color="match ($version['status']) { 'LATEST' => 'success', 'BEHIND' => 'danger', default => 'gray' }">
                                    {{ $version['status'] }}
                                </x-filament::badge>
                            </td>
                            <td class="py-2 pe-4">{{ $version['lastUpgrade'] ?? '—' }}</td>
                            <td class="py-2 pe-4">{{ $version['lastUpgrade'] ? $version['upgradedFrom'].' → '.$version['upgradedTo'] : '—' }}</td>
                            <td class="py-2 pe-4">{{ $version['lastPatch'] ?? '—' }}</td>
                            <td class="py-2">{{ $version['daysSinceUpgrade'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Rows that differ from {{ $baseline }}</x-slot>
        <x-slot name="description">
            {{ number_format($totalCompared) }} rows compared. Users and per-user tables are not compared.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left divide-y divide-gray-200 dark:divide-white/10">
                <thead>
                    <tr class="text-gray-600 dark:text-gray-400">
                        <th class="py-2 pe-4 font-semibold">Area</th>
                        <th class="py-2 pe-4 font-semibold text-center">Rows compared</th>
                        @foreach ($others as $name)
                            <th class="py-2 pe-4 font-semibold text-center">{{ $name }}</th>
                        @endforeach
                        <th class="py-2 font-semibold text-center">Not identical across all</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($summary['tally'] as $row)
                        <tr>
                            <td class="py-2 pe-4">{{ $row['area'] }}</td>
                            <td class="py-2 pe-4 text-center">{{ number_format($row['compared']) }}</td>
                            @foreach ($others as $name)
                                <td class="py-2 pe-4 text-center">{{ number_format($row['differences'][$name] ?? 0) }}</td>
                            @endforeach
                            <td class="py-2 text-center">{{ number_format($row['notIdentical']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold">
                        <td class="py-2 pe-4">Total</td>
                        <td class="py-2 pe-4 text-center">{{ number_format($totalCompared) }}</td>
                        @foreach ($others as $name)
                            <td class="py-2 pe-4 text-center">{{ number_format($summary['totalDifferences'][$name] ?? 0) }}</td>
                        @endforeach
                        <td class="py-2 text-center">{{ number_format($summary['totalNotIdentical']) }}</td>
                    </tr>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <td class="py-2 pe-4">Share of rows differing</td>
                        <td class="py-2 pe-4"></td>
                        @foreach ($others as $name)
                            <td class="py-2 pe-4 text-center">
                                {{ $totalCompared > 0 ? round(100 * ($summary['totalDifferences'][$name] ?? 0) / $totalCompared) : 0 }}%
                            </td>
                        @endforeach
                        <td class="py-2"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Downloads</x-slot>
        <x-slot name="description">
            Generated {{ $summary['generatedAt'] }}. Results are kept for {{ $this->retentionMinutes() }} minutes, then deleted.
        </x-slot>

        <div class="flex flex-wrap gap-3">
            <x-filament::button tag="a" :href="$this->downloadUrl(\App\Support\SysCompare\SysCompareArtifact::Report)" color="gray" icon="heroicon-o-document-text">
                HTML report
            </x-filament::button>
            <x-filament::button tag="a" :href="$this->downloadUrl(\App\Support\SysCompare\SysCompareArtifact::Csv)" color="gray" icon="heroicon-o-archive-box-arrow-down">
                CSV bundle (.zip)
            </x-filament::button>
            <x-filament::button tag="a" :href="$this->downloadUrl(\App\Support\SysCompare\SysCompareArtifact::Xlsx)" color="gray" icon="heroicon-o-table-cells">
                Excel workbook
            </x-filament::button>
            @if ($this->hasTemplate())
                <x-filament::button tag="a" :href="$this->downloadUrl(\App\Support\SysCompare\SysCompareArtifact::Template)" color="gray" icon="heroicon-o-document-plus">
                    Missing definitions template
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Report</x-slot>
        <x-slot name="description">Click a column header in a table to sort it. Amber cells differ from {{ $baseline }}.</x-slot>

        {{-- The report is a separate document, shown in a sandbox: its scripts can sort tables but cannot reach this page. --}}
        <iframe
            src="{{ $this->downloadUrl(\App\Support\SysCompare\SysCompareArtifact::Report, inline: true) }}"
            sandbox="allow-scripts"
            title="Comparison report"
            class="w-full rounded-lg ring-1 ring-gray-950/10 dark:ring-white/20 bg-white"
            style="height: 75vh"
        ></iframe>
    </x-filament::section>

    <div>
        <x-filament::button color="gray" wire:click="startNewComparison" icon="heroicon-o-arrow-path">
            Hide these results
        </x-filament::button>
    </div>
</div>
