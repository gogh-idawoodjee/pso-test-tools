@props(['shifts'])

@php
    use Carbon\Carbon;

    $firstShiftDate = collect($shifts)
        ->map(fn($s) => Carbon::parse($s['start_datetime'])->tz(config('app.timezone'))->startOfDay())
        ->min();

    $startDate = $firstShiftDate ?? now()->startOfDay();

    $activeDates = collect($shifts)
        ->map(fn($s) => Carbon::parse($s['start_datetime'])->tz(config('app.timezone'))->startOfDay()->toDateString())
        ->unique()
        ->values();

    $availableColors = ['#f97316', '#10b981', '#3b82f6', '#eab308', '#6366f1', '#ec4899', '#14b8a6', '#8b5cf6', '#f43f5e', '#84cc16'];

    $uniqueRegions = collect($shifts)
        ->flatMap(fn($s) => $s['region_availability'] ?? [])
        ->map(fn($a) => [
            'id' => $a['region_id'] ?? 'unknown',
            'description' => $a['region_description'] ?? 'Unknown Region',
            'active' => $a['region_active'] ?? true,
        ])
        ->unique('id')
        ->values();

    $legendColorMap = [];
    if (!empty($availableColors) && is_array($availableColors)) {
        foreach ($uniqueRegions as $i => $region) {
            $legendColorMap[$region['id']] = $availableColors[$i % count($availableColors)];
        }
    }
@endphp

<div class="mb-4 space-y-2">
    <h2 class="text-sm font-semibold text-gray-600 dark:text-gray-300">Region Legend</h2>
    <div class="flex flex-wrap gap-4">
        @foreach ($uniqueRegions as $region)
            @php
                $color = $legendColorMap[$region['id']] ?? '#ccc';
                $bgStyle = $region['active']
                    ? "background-color: {$color};"
                    : "background-image: repeating-linear-gradient(45deg, {$color}, {$color} 4px, rgba(255,255,255,0.5) 4px, rgba(255,255,255,0.5) 8px); background-blend-mode: multiply;";
            @endphp
            <div class="flex items-center text-sm gap-2">
                <div class="w-4 h-4 rounded-sm border border-gray-300 mr-2" style="{{ $bgStyle }}"></div>
                <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $region['description'] }}</span>
            </div>
        @endforeach
    </div>
</div>

<div x-data="ganttTooltips()" class="space-y-6">
    @foreach ($activeDates as $dateString)
        @php
            $day = Carbon::parse($dateString);
            $dayShifts = collect($shifts)->filter(fn($s) =>
                Carbon::parse($s['start_datetime'])->tz(config('app.timezone'))->startOfDay()->toDateString() === $day->toDateString()
            );

            $maxRegionBars = $dayShifts->max(fn($s) => count($s['region_availability'] ?? []));
            $regionBarHeight = 6; // 6px per bar
            $regionBarPadding = 4; // Extra room above/below
            $shiftHeight = 40; // h-8 = 2rem = 32px
            $stackCount = $dayShifts->map(fn($s) =>
                    collect($s['region_availability'] ?? [])
                        ->pluck('region_id')
                        ->unique()
                        ->count()
                )->max();
                $totalHeight = $shiftHeight + ($stackCount * $regionBarHeight) + $regionBarPadding;
        @endphp

        <div class="flex flex-col space-y-2">
            <div class="text-sm font-semibold text-gray-600 dark:text-gray-300">{{ $day->format('M d, Y') }}</div>

            <div class="flex flex-col">
                <div class="relative w-full h-6 mb-1">
                    @for ($i = 0; $i < 24; $i++)
                        <div class="absolute top-0 bottom-0 w-px bg-gray-300 dark:bg-gray-700"
                             style="left: {{ ($i / 24) * 100 }}%; z-index: 0;"></div>
                        @if ($i % 4 === 0)
                            <div class="absolute top-0 text-xs text-gray-500 dark:text-gray-400 font-mono"
                                 style="left: {{ ($i / 24) * 100 }}%; transform: translateX(-50%); z-index: 10;">
                                {{ str_pad($i, 2, '0', STR_PAD_LEFT) }}:00
                            </div>
                        @endif
                    @endfor
                </div>

                <div class="relative  w-full bg-gray-100 dark:bg-gray-800 rounded overflow-visible"
                     style="height: {{ $totalHeight }}px;">
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
                            style="left: {{ $leftPercent }}%; width: {{ max($widthPercent, 2) }}%; background-color: {{ $barColor }}; color: {{ $textColor }};"
                            @mouseenter="show = true"
                            @mouseleave="show = false"
                        >
                            {{ $shift['label'] ?? 'Shift' }}

                            <div
                                x-show="show"
                                x-transition
                                class="absolute -top-14 left-1/2 -translate-x-1/2 z-[9999] px-3 py-2 text-xs rounded shadow-md whitespace-pre max-w-xs"
                                style="background-color: rgba(17, 24, 39, 1); color: #ffffff; border: 1px solid rgba(75, 85, 99, 1);"
                            >
                                {{ $isManual ? 'Manual Shift' : 'Planned Shift' }}<br>
                                Start: {{ $start->format('Y-m-d H:i') }}<br>
                                End: {{ $end->format('Y-m-d H:i') }}
                            </div>
                        </div>
                    @endforeach

                    @foreach ($shift['breaks'] ?? [] as $break)
                        @php
                            $breakStart = Carbon::parse($break['start'])->tz(config('app.timezone'));
                            $breakEnd = Carbon::parse($break['end'])->tz(config('app.timezone'));

                            $breakStartSec = $breakStart->secondsSinceMidnight();
                            $breakEndSec = $breakEnd->secondsSinceMidnight();

                            $breakLeft = ($breakStartSec / 86400) * 100;
                            $breakWidth = (($breakEndSec - $breakStartSec) / 86400) * 100;
                        @endphp

                        <div
                            class="absolute top-1 h-8 rounded z-30 opacity-80"
                            style="
                                    left: {{ $breakLeft }}%;
                                    width: {{ max($breakWidth, 1) }}%;
                                    border: 1px solid #d0d2d9;
                                        background-image: repeating-linear-gradient(45deg,
                                            rgba(75, 85, 99, 0) 0px,
                                            rgba(75, 85, 99, 0) 4px,
                                            #4b5563 4px,
                                            #4b5563 8px);
                                        background-color: #9ca3af; /* Medium gray background */
                                    "
                            title="Break: {{ $breakStart->format('H:i') }} - {{ $breakEnd->format('H:i') }}"
                        ></div>
                    @endforeach

                    @foreach ($dayShifts as $shift)
                        @php
                            // Compute unique Y-index for each region_id in this shift
                            $regionLineMap = [];
                            $lineIndex = 0;
                            foreach (($shift['region_availability'] ?? []) as $ra) {
                                $rid = $ra['region_id'] ?? 'unknown';
                                if (!isset($regionLineMap[$rid])) {
                                    $regionLineMap[$rid] = $lineIndex++;
                                }
                            }
                        @endphp
                        @foreach ($shift['region_availability'] ?? [] as $availability)
                            @php
                                $availStart = Carbon::parse($availability['start'])->tz(config('app.timezone'));
                                $availEnd = Carbon::parse($availability['end'])->tz(config('app.timezone'));
                                $availStartSec = $availStart->secondsSinceMidnight();
                                $availEndSec = $availEnd->secondsSinceMidnight();
                                $availLeftPercent = ($availStartSec / 86400) * 100;
                                $availWidthPercent = (($availEndSec - $availStartSec) / 86400) * 100;
                                $regionId = $availability['region_id'] ?? 'unknown';
                                if (!isset($legendColorMap[$regionId])) {
                                    $legendColorMap[$regionId] = '#ccc';
                                }
                                $availColor = $legendColorMap[$regionId];
                                $regionDescription = $availability['region_description'] ?? 'Unknown region';
                                $regionIndex = $regionLineMap[$regionId] ?? 0;
                                $top = 40 + ($regionIndex * 6);
//                                $top = 40 + ($loop->index * 6);
                                $style = "top: {$top}px; left: {$availLeftPercent}%; width: " . max($availWidthPercent, 1) . "%; background-color: {$availColor};";
                                if (isset($availability['region_active']) && !$availability['region_active']) {
                                    $style .= " background-image: repeating-linear-gradient(45deg, {$availColor}, {$availColor} 4px, rgba(255,255,255,0.5) 4px, rgba(255,255,255,0.5) 8px); background-blend-mode: multiply;";
                                }
                            @endphp

                            <div
                                class="absolute h-1.5 rounded-sm opacity-90 z-10 cursor-pointer"
                                style="{{ $style }}"
                                @mouseenter="showRegionTooltip($event, '{{ $regionDescription }}', '{{ $availStart->format('Y-m-d H:i') }}', '{{ $availEnd->format('Y-m-d H:i') }}', {{ $availability['region_active'] ? 'true' : 'false' }})"
                                @mouseleave="hideTooltip()"
                            ></div>
                        @endforeach
                    @endforeach

                    <div id="tooltip-portal" class="fixed inset-0 pointer-events-none" style="z-index: 99999;"
                         x-ref="tooltipPortal"></div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<script>
    function ganttTooltips() {
        return {
            tooltipContent: null,

            showRegionTooltip(event, region, start, end, isActive) {
                if (this.tooltipContent) {
                    this.hideTooltip();
                }

                const tooltip = document.createElement('div');
                tooltip.className = 'absolute px-3 py-2 text-xs rounded shadow-lg whitespace-pre max-w-xs';
                tooltip.style.cssText = 'background-color: rgba(17, 24, 39, 1); color: #ffffff; border: 1px solid rgba(75, 85, 99, 1); z-index: 99999;';
                tooltip.innerHTML = `${region}<br>Start: ${start}<br>End: ${end}<br>Status: ${isActive ? 'Active' : 'Not Active'}`;

                const portal = document.getElementById('tooltip-portal');
                portal.appendChild(tooltip);
                this.tooltipContent = tooltip;
                this.positionTooltipAtCursor(event);
                document.addEventListener('mousemove', this.moveTooltip.bind(this));
            },

            moveTooltip(event) {
                this.positionTooltipAtCursor(event);
            },

            positionTooltipAtCursor(event) {
                if (!this.tooltipContent) return;
                this.tooltipContent.style.transform = 'none';
                this.tooltipContent.style.left = (event.clientX + 10) + 'px';
                this.tooltipContent.style.top = (event.clientY - 80) + 'px';
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
