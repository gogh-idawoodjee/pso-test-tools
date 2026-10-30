<x-filament-panels::page>
    {{ $this->form }}

    @if ($summary)
        @php
            $types = $summary['resource_types'];
        @endphp

        <x-filament::section heading="{{ $summary['file'] }}" description="{{ $summary['dataset_id'] }}, seed {{ $summary['seed'] }}, now {{ $summary['now'] }}, {{ $summary['horizon_days'] }} days ahead and 7 back. {{ number_format($summary['bytes'] / 1024, 0) }} KB.">
            <div class="space-y-6 text-sm">
                <div class="flex flex-wrap gap-3">
                    {{ $this->downloadAction }}
                </div>

                <div>
                    <h3 class="mb-2 font-medium text-gray-950 dark:text-white">Resources by region and type ({{ $summary['resources'] }})</h3>
                    <table class="text-left">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="pe-6 font-medium">Region</th>
                                @foreach ($types as $type)
                                    <th class="pe-6 font-medium">{{ $type }}</th>
                                @endforeach
                                <th class="pe-6 font-medium">Shifts</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-950 dark:text-white">
                            @foreach ($summary['regions'] as $region)
                                <tr>
                                    <td class="pe-6 font-mono">{{ $region['region'] }}</td>
                                    @foreach ($types as $type)
                                        <td class="pe-6">{{ $region['resources'][$type] ?? '' }}</td>
                                    @endforeach
                                    <td class="pe-6">{{ number_format($region['shifts']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($summary['extra_regions'] !== [])
                        <p class="mt-2 text-gray-600 dark:text-gray-400">Also available in a second region: {{ implode('; ', $summary['extra_regions']) }}.</p>
                    @endif
                </div>

                <div>
                    <h3 class="mb-2 font-medium text-gray-950 dark:text-white">Shifts by type ({{ number_format($summary['shifts']) }})</h3>
                    <table class="text-left">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="pe-6 font-medium">Type</th>
                                <th class="pe-6 font-medium">Days</th>
                                <th class="pe-6 font-medium">Resources</th>
                                <th class="pe-6 font-medium">Shifts</th>
                                <th class="pe-6 font-medium">Per resource</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-950 dark:text-white">
                            @foreach ($summary['types'] as $type)
                                <tr>
                                    <td class="pe-6 font-mono">{{ $type['type'] }}</td>
                                    <td class="pe-6">{{ $type['days'] }}</td>
                                    <td class="pe-6">{{ $type['resources'] }}</td>
                                    <td class="pe-6">{{ number_format($type['shifts']) }}</td>
                                    <td class="pe-6">{{ $type['min'] === $type['max'] ? $type['min'] : $type['min'].' to '.$type['max'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-gray-600 dark:text-gray-400">
                        Split priority: {{ $summary['monday_shifts_at_zero'] }} of {{ $summary['monday_shifts'] }} Monday shifts at 0,
                        {{ $summary['other_shifts_at_one'] }} of {{ $summary['other_shifts'] }} other shifts at 1.
                    </p>
                </div>

                <div>
                    <h3 class="mb-2 font-medium text-gray-950 dark:text-white">First and last shift by region</h3>
                    <table class="text-left">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="pe-6 font-medium">Region</th>
                                <th class="pe-6 font-medium">Time zone</th>
                                <th class="pe-6 font-medium">First (local)</th>
                                <th class="pe-6 font-medium">First (UTC)</th>
                                <th class="pe-6 font-medium">Last (local)</th>
                                <th class="pe-6 font-medium">Last (UTC)</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-950 dark:text-white">
                            @foreach ($summary['regions'] as $region)
                                <tr>
                                    <td class="pe-6 font-mono">{{ $region['region'] }}</td>
                                    <td class="pe-6">{{ $region['time_zone'] }}</td>
                                    @if ($region['first_local'])
                                        <td class="pe-6">{{ $region['first_local'] }}</td>
                                        <td class="pe-6 font-mono">{{ $region['first_utc'] }}</td>
                                        <td class="pe-6">{{ $region['last_local'] }}</td>
                                        <td class="pe-6 font-mono">{{ $region['last_utc'] }}</td>
                                    @else
                                        <td class="pe-6 text-gray-500 dark:text-gray-400" colspan="4">No resources based here</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    <h3 class="mb-2 font-medium text-gray-950 dark:text-white">Shift start times</h3>
                    <p class="mb-2 text-gray-600 dark:text-gray-400">Each run of days with the same start. A daylight saving change shows as a new row with the same local time and a different UTC time.</p>
                    <table class="text-left">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="pe-6 font-medium">Region</th>
                                <th class="pe-6 font-medium">Type</th>
                                <th class="pe-6 font-medium">Local</th>
                                <th class="pe-6 font-medium">UTC</th>
                                <th class="pe-6 font-medium">From</th>
                                <th class="pe-6 font-medium">To</th>
                                <th class="pe-6 font-medium">Shifts</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-950 dark:text-white">
                            @foreach ($summary['bands'] as $band)
                                <tr>
                                    <td class="pe-6 font-mono">{{ $band['region'] }}</td>
                                    <td class="pe-6 font-mono">{{ $band['type'] }}</td>
                                    <td class="pe-6">{{ $band['local'] }}</td>
                                    <td class="pe-6 font-mono">{{ $band['utc'] }}</td>
                                    <td class="pe-6">{{ $band['from'] }}</td>
                                    <td class="pe-6">{{ $band['to'] }}</td>
                                    <td class="pe-6">{{ $band['shifts'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </x-filament::section>

        {{ $this->sendForm }}
    @endif
</x-filament-panels::page>
