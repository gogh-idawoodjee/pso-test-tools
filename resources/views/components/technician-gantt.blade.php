@props(['shifts'])

@php
    use Carbon\Carbon;

    $firstShiftDate = collect($shifts)
        ->map(fn($s) => Carbon::parse($s['start_datetime'])->startOfDay())
        ->min();

    $startDate = $firstShiftDate ?? now()->startOfDay();

    $activeDates = collect($shifts)
        ->map(fn($s) => Carbon::parse($s['start_datetime'])->startOfDay()->toDateString())
        ->unique()
        ->values();
@endphp

<div x-data="ganttTooltips()" class="space-y-6">
    @foreach ($activeDates as $dateString)
        @php
            $day = Carbon::parse($dateString);
            $dayShifts = collect($shifts)->filter(fn($s) =>
                Carbon::parse($s['start_datetime'])->startOfDay()->toDateString() === $day->toDateString()
            );
        @endphp

        <div class="flex flex-col space-y-2">
            <div class="text-sm font-semibold text-gray-600 dark:text-gray-300">{{ $day->format('M d, Y') }}</div>

            <div class="relative h-16 w-full bg-gray-100 dark:bg-gray-800 rounded overflow-visible">
                {{-- Shift bars --}}
                @foreach ($dayShifts as $shift)
                    @php
                        $start = Carbon::parse($shift['start_datetime'])->tz(config('app.timezone'));
$end = Carbon::parse($shift['end_datetime'])->tz(config('app.timezone'));

if ($end->lessThanOrEqualTo($start)) {
    $end = $start->copy()->addHour();
}

$startSeconds = $start->secondsSinceMidnight();
$endSeconds = $end->secondsSinceMidnight();

$leftPercent = ($startSeconds / 86400) * 100;
$widthPercent = (($endSeconds - $startSeconds) / 86400) * 100;

$isManual = $shift['manual_scheduling_only'] ?? false;
$barColor = $isManual ? '#facc15' : '#3b82f6';
$textColor = $isManual ? '#000000' : '#ffffff';
                    @endphp

                    <div
                        x-data="{ show: false }"
                        class="absolute top-1 h-8 rounded flex items-center justify-center text-xs px-2 z-20"
                        style="
                            left: {{ $leftPercent }}%;
                            width: {{ max($widthPercent, 2) }}%;
                            background-color: {{ $barColor }};
                            color: {{ $textColor }};
                        "
                        @mouseenter="show = true"
                        @mouseleave="show = false"
                    >
                        {{ $shift['label'] ?? 'Shift' }}

                        <div
                            x-show="show"
                            x-transition
                            class="absolute -top-14 left-1/2 -translate-x-1/2 z-[9999] px-3 py-2 text-xs rounded shadow-md whitespace-pre max-w-xs"
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

                {{-- Region availability bars WITH tooltips --}}
                @foreach ($dayShifts as $shift)
                    @foreach ($shift['region_availability'] ?? [] as $availability)
                        @php
                            $availStart = Carbon::parse($availability['start'])->tz(config('app.timezone'));
                            $availEnd = Carbon::parse($availability['end'])->tz(config('app.timezone'));
                            $availStartSec = $availStart->secondsSinceMidnight();
                            $availEndSec = $availEnd->secondsSinceMidnight();
                            $availLeftPercent = ($availStartSec / 86400) * 100;
                            $availWidthPercent = (($availEndSec - $availStartSec) / 86400) * 100;
                            $availColor = $availability['full_coverage'] ? '#34d399' : '#f97316';
                            $regionDescription = $availability['region_description'] ?? 'Unknown region';
                        @endphp

                        <div
                            class="absolute top-[40px] h-1.5 rounded-sm opacity-90 z-10 cursor-pointer"
                            style="
                                left: {{ $availLeftPercent }}%;
                                width: {{ max($availWidthPercent, 1) }}%;
                                background-color: {{ $availColor }};
                            "
                            @mouseenter="showRegionTooltip($event, '{{ $regionDescription }}', '{{ $availStart->format('Y-m-d H:i') }}', '{{ $availEnd->format('Y-m-d H:i') }}')"
                            @mouseleave="hideTooltip()"
                        >
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    @endforeach

    <!-- Tooltip portal target -->
    <div id="tooltip-portal" class="fixed inset-0 pointer-events-none" style="z-index: 99999;"
         x-ref="tooltipPortal"></div>
</div>

<script>
    function ganttTooltips() {
        return {
            tooltipContent: null,

            showRegionTooltip(event, region, start, end) {
                if (this.tooltipContent) {
                    this.hideTooltip();
                }

                // Create tooltip element
                const tooltip = document.createElement('div');
                tooltip.className = 'absolute px-3 py-2 text-xs rounded shadow-lg whitespace-pre max-w-xs';
                tooltip.style.cssText = 'background-color: rgba(17, 24, 39, 1); color: #ffffff; border: 1px solid rgba(75, 85, 99, 1); z-index: 99999;';
                tooltip.innerHTML = `${region}<br>Start: ${start}<br>End: ${end}`;

                // Position tooltip
                tooltip.style.left = event.clientX + 'px';
                tooltip.style.top = (event.clientY - 80) + 'px';
                tooltip.style.transform = 'translateX(-50%)';

                // Find or create portal element
                let portal = document.getElementById('tooltip-portal');
                if (!portal) {
                    portal = document.createElement('div');
                    portal.id = 'tooltip-portal';
                    portal.className = 'fixed inset-0 pointer-events-none';
                    portal.style.zIndex = '99999';
                    document.body.appendChild(portal);
                }

                // Add tooltip to portal
                portal.appendChild(tooltip);
                this.tooltipContent = tooltip;

                // Update tooltip position on mouse move
                document.addEventListener('mousemove', this.moveTooltip);
            },

            moveTooltip(event) {
                if (this.tooltipContent) {
                    this.tooltipContent.style.left = event.clientX + 'px';
                    this.tooltipContent.style.top = (event.clientY - 80) + 'px';
                }
            },

            hideTooltip() {
                if (this.tooltipContent) {
                    this.tooltipContent.remove();
                    this.tooltipContent = null;
                    document.removeEventListener('mousemove', this.moveTooltip);
                }
            }
        };
    }
</script>
