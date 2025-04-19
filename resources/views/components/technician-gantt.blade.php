@props(['shifts'])

@php
    use Carbon\Carbon;
     // figure out the earliest shift date, or fall back to today
         // 1️⃣ Compute dynamic start date
    $firstShiftDate = collect($shifts)
        ->map(fn($s) => Carbon::parse($s['start'])->startOfDay())
        ->min();

    $startDate = $firstShiftDate ?? now()->startOfDay();
@endphp

<div class="overflow-x-auto bg-white dark:bg-gray-900 rounded shadow border border-gray-300 dark:border-gray-700">
    <table class="min-w-full table-fixed text-sm">
        <thead class="bg-gray-100 dark:bg-gray-800 text-xs uppercase text-gray-700 dark:text-gray-300">
        <tr>
            <th class="w-36 text-left px-2 py-2 border-r border-gray-300 dark:border-gray-600">Date</th>
            @for ($i = 0; $i < 24; $i++)
                <th class="w-16 text-center border-r border-gray-100 dark:border-gray-700 font-normal">
                    {{ str_pad($i, 2, '0', STR_PAD_LEFT) }}:00
                </th>
            @endfor
        </tr>
        </thead>
        <tbody>
        @for ($d = 0; $d < 30; $d++)
            @php
                $day = (clone $startDate)->addDays($d);
                $dayShifts = collect($shifts); // adjust if filtering by date
            @endphp
            <tr class="border-t border-gray-200 dark:border-gray-700 h-10">
                <td class="px-2 py-1 text-left font-medium text-gray-600 dark:text-gray-300 border-r border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 whitespace-nowrap">
                    {{ $day->format('M d, Y') }}
                </td>
                <td colspan="24" class="relative bg-white dark:bg-gray-900">
                    @foreach ($dayShifts as $shift)
                        @php
                            $start = Carbon::parse($shift['start'])->tz(config('app.timezone'));
                            $end = Carbon::parse($shift['end'])->tz(config('app.timezone'));

                            if ($end->lessThanOrEqualTo($start)) {
                                $end = $start->copy()->addHour();
                            }

                            $startSeconds = $start->secondsSinceMidnight();
                            $endSeconds = $end->secondsSinceMidnight();

                            $leftPercent = ($startSeconds / 86400) * 100;
                            $widthPercent = (($endSeconds - $startSeconds) / 86400) * 100;

                            $isManual = $shift['manual_scheduling_only'] ?? false;
                            $barColor = $isManual ? '#facc15' : '#3b82f6'; // yellow or blue
                            $textColor = $isManual ? '#000000' : '#ffffff';

                            $tooltip = sprintf(
                                "%s\nStart: %s\nEnd: %s",
                                $isManual ? 'Manual Shift' : 'Planned Shift',
                                $start->format('Y-m-d H:i'),
                                $end->format('Y-m-d H:i'),
                            );
                        @endphp

                        <div
                            x-cloak
                            x-data="{ show: false }"
                            x-on:mouseenter="show = true"
                            x-on:mouseleave="show = false"
                            class="absolute top-1 bottom-1 text-xs rounded px-2 py-0.5 shadow-sm flex items-center justify-center transition"
                            style="
        left: {{ $leftPercent }}%;
        width: {{ max($widthPercent, 2) }}%;
        min-width: 40px;
        background-color: {{ $barColor }};
        color: {{ $textColor }};
    "
                        >
                            {{ $shift['label'] ?? 'Shift' }}

                            <!-- Tooltip -->
                            <div
                                x-cloak
                                x-bind:class="{ 'opacity-0 scale-95 pointer-events-none': !show, 'opacity-100 scale-100': show }"
                                class="absolute -top-14 left-1/2 -translate-x-1/2 z-50 px-3 py-2 text-xs rounded shadow-md whitespace-pre max-w-xs transition-all duration-200"
                                style="
            background-color: rgba(17, 24, 39, 1);
            color: #ffffff;
            border: 1px solid rgba(75, 85, 99, 1);
        "
                            >
                                {{ $isManual ? 'Manual Shift' : 'Planned Shift' }}<br>
                                Start: {{ $start->format('Y-m-d H:i') }}<br>
                                End: {{ $end->format('Y-m-d H:i') }}
                            </div>
                        </div>

                    @endforeach
                </td>
            </tr>
        @endfor
        </tbody>
    </table>
</div>
