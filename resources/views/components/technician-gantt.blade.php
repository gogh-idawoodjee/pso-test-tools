@props(['shifts', 'showBackgrounds' => false])

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
    // More distinct background colors for groups with better contrast and distinguishable hues
    $groupColors = [
        '#d6eaff', // Stronger light blue
        '#ffe0f0', // Stronger light pink
        '#d1ffdb', // Stronger light green
        '#d1ffdb', // Stronger light green
        '#fff0c0', // Stronger light amber
        '#e5d0ff', // Stronger light purple
        '#d0dbff', // Stronger light indigo
        '#ffffb3', // Stronger light yellow
        '#bfffef', // Stronger light teal
        '#d9e5f1', // Stronger light slate
        '#ffd9d9', // Stronger light red
    ];

    $uniqueRegions = collect($shifts)
        ->flatMap(fn($s) => $s['region_availability'] ?? [])
        ->map(fn($a) => [
            'id' => $a['region_id'] ?? 'unknown',
            'description' => $a['region_description'] ?? 'Unknown Region',
            'active' => $a['region_active'] ?? true,
        ])
        ->unique('id')
        ->values();

    // Identify unique region groups
    $uniqueRegionGroups = collect($shifts)
        ->flatMap(fn($s) => $s['region_availability'] ?? [])
        ->map(fn($a) => [
            'id' => $a['region_group_id'] ?? 'unknown',
            'description' => $a['region_group_description'] ?? 'Unknown Group',
        ])
        ->unique('id')
        ->values();

    $legendColorMap = [];
    if (!empty($availableColors) && is_array($availableColors)) {
        foreach ($uniqueRegions as $i => $region) {
            $legendColorMap[$region['id']] = $availableColors[$i % count($availableColors)];
        }
    }

    $groupColorMap = [];
    if (!empty($groupColors) && is_array($groupColors)) {
        foreach ($uniqueRegionGroups as $i => $group) {
            $groupColorMap[$group['id']] = $groupColors[$i % count($groupColors)];
        }
    }
@endphp

<div class="mb-4 flex items-center justify-between">
    <div class="flex flex-wrap gap-4">
        @foreach ($uniqueRegions as $region)
            @php
                $color = $legendColorMap[$region['id']] ?? '#ccc';
                $bgStyle = $region['active']
                    ? "background-color: {$color};"
                    : "background-color: {$color}; background-image: repeating-linear-gradient(45deg, {$color}, {$color} 4px, rgba(255,255,255,0.5) 4px, rgba(255,255,255,0.5) 8px); background-blend-mode: multiply;";
            @endphp
            <div class="flex items-center text-sm gap-2">
                <div class="w-4 h-4 rounded-sm border border-gray-300 mr-2" style="{{ $bgStyle }}"></div>
                <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $region['description'] }}</span>
            </div>
        @endforeach
    </div>
</div>

<div class="mb-4 space-y-2">
    <h2 class="text-sm font-semibold text-gray-600 dark:text-gray-300">Group Legend</h2>
    <div class="flex flex-wrap gap-4 items-center">
        @foreach ($uniqueRegionGroups as $group)
            @php
                $color = $groupColorMap[$group['id']] ?? '#f1f5f9';
            @endphp
            <div class="flex items-center text-sm gap-2">
                <div class="w-4 h-4 rounded-sm border border-gray-300 mr-2"
                     style="background-color: {{ $color }}; {{ !$showBackgrounds ? 'opacity: 0.5;' : '' }}">
                </div>
                <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $group['description'] }}</span>
            </div>
        @endforeach
    </div>
</div>

<div x-data="{
    tooltipContent: null,
    showGroupBackgrounds: true,

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
}" class="space-y-6">
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

            // Get all region groups for this day
            $allDayRegionGroups = $dayShifts
                ->flatMap(fn($s) => collect($s['region_availability'] ?? [])
                    ->groupBy('region_group_id')
                    ->map(fn($group, $id) => [
                        'id' => $id,
                        'description' => $group->first()['region_group_description'] ?? 'Unknown Group',
                        'count' => $group->pluck('region_id')->unique()->count()
                    ])
                )
                ->groupBy('id')
                ->map(fn($group) => [
                    'id' => $group->first()['id'],
                    'description' => $group->first()['description'],
                    'count' => $group->sum('count')
                ])
                ->values();

            $stackCount = $allDayRegionGroups->sum('count');
            $groupCount = $allDayRegionGroups->count();

            // Define consistent padding for top and bottom
            $topPadding = $regionBarPadding;
            $bottomPadding = $topPadding;

            $totalRegionStackHeight = $stackCount * $regionBarHeight;
            $groupSpacerHeight = $groupCount * 6;
            $bottomPadding = 8; // Give it a bit more room at the bottom
            // Calculate total height with equal top and bottom padding
//            $totalHeight = $shiftHeight + ($stackCount * $regionBarHeight) + ($groupCount * 6) + $topPadding + $bottomPadding;
$minTotalHeight = $shiftHeight + 48; // enough for 1 group + some breathing room
$totalHeight = $shiftHeight
    + ($stackCount > 0 ? $totalRegionStackHeight + $groupSpacerHeight + $topPadding + $bottomPadding : 0);

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

                <div class="relative w-full bg-gray-100 dark:bg-gray-800 rounded overflow-visible"
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
                            $barColor = $isManual ? '#facc15' : '#f5deb3';
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

                        @foreach ($shift['breaks'] ?? [] as $break)
                            @php
                                $breakStart = Carbon::parse($break['start'])->tz(config('app.timezone'));
                                $breakEnd = Carbon::parse($break['end'])->tz(config('app.timezone'));

                                // Only show breaks that are within the same day as the shift
                                if ($breakStart->toDateString()===($day->toDateString())) {
                                $breakStartSec = $breakStart->hour * 3600 + $breakStart->minute * 60 + $breakStart->second;
                                $breakEndSec = $breakEnd->hour * 3600 + $breakEnd->minute * 60 + $breakEnd->second;

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
                            @php
                                }
                            @endphp
                        @endforeach
                    @endforeach

                    @php
                        // We need to track the vertical position for each group
                        $topPadding = $regionBarPadding;
                        $currentTop = $shiftHeight + $topPadding; // Start after the shift bars with consistent padding
                        $groupPositions = [];
                        $regionLineMap = []; // Track which region IDs have already been assigned lines

                        // First, collect all unique region IDs per group across all shifts
                        $allRegionsByGroup = $dayShifts->flatMap(function($shift) {
                            return collect($shift['region_availability'] ?? []);
                        })
                        ->groupBy('region_group_id')
                        ->map(function($regions) {
                            // Get unique region IDs within this group
                            return $regions->pluck('region_id')->unique()->values();
                        });

                        // First, identify all region groups for this day and calculate their positions
                        $regionGroupsByShift = $dayShifts->flatMap(function($shift) {
                            return collect($shift['region_availability'] ?? [])
                                ->groupBy('region_group_id')
                                ->map(function($regions, $groupId) use ($shift) {
                                    return [
                                        'group_id' => $groupId,
                                        'group_description' => $regions->first()['region_group_description'] ?? 'Unknown Group',
                                        'shift_id' => $shift['id'],
                                        'regions' => $regions->toArray()
                                    ];
                                })->values();
                        });

                        // Prepare region line maps and group positions
                        foreach ($allRegionsByGroup as $groupId => $uniqueRegions) {
                            // Initialize line indices for each region in this group
                            $regionLineMap[$groupId] = [];
                            $lineIndex = 0;
                            foreach ($uniqueRegions as $regionId) {
                                $regionLineMap[$groupId][$regionId] = $lineIndex++;
                            }

                            // Calculate group height based on actual unique regions
                            $height = (count($uniqueRegions) * $regionBarHeight) + 6; // Height + group label
                            $groupPositions[$groupId] = [
                                'top' => $currentTop,
                                'height' => $height
                            ];
                            $currentTop += $height + 4; // 2px gap between groups
                        }

                        $regionGroupsPositioned = collect();
                        foreach ($regionGroupsByShift as $groupInfo) {
                            $groupId = $groupInfo['group_id'];

                            $regionGroupsPositioned->push([
                                'group_id' => $groupId,
                                'group_description' => $groupInfo['group_description'],
                                'shift_id' => $groupInfo['shift_id'],
                                'regions' => $groupInfo['regions'],
                                'position' => $groupPositions[$groupId],
                                'lineMap' => $regionLineMap[$groupId] ?? []
                            ]);
                        }
                    @endphp

                    @foreach ($regionGroupsPositioned as $groupInfo)
                        @php
                            $groupId = $groupInfo['group_id'];
                            $groupDesc = $groupInfo['group_description'];
                            $groupTop = $groupInfo['position']['top'];
                            $groupHeight = $groupInfo['position']['height'];
                            $groupColor = $groupColorMap[$groupId] ?? '#f1f5f9';

                            // Find the earliest start and latest end for this group's regions
                            // Instead of using just the regions' times, use the shift times as the bounds
                            $shiftStart = null;
                            $shiftEnd = null;

                            foreach ($dayShifts as $s) {
                                if ($s['id'] == $groupInfo['shift_id']) {
                                    $shiftStart = Carbon::parse($s['start_datetime'])->tz(config('app.timezone'));
                                    $shiftEnd = Carbon::parse($s['end_datetime'])->tz(config('app.timezone'));
                                    break;
                                }
                            }

                            $shiftStartSec = $shiftStart->secondsSinceMidnight();
                            $shiftEndSec = $shiftEnd->secondsSinceMidnight();
                            $groupLeftPercent = ($shiftStartSec / 86400) * 100;
                            $groupWidthPercent = (($shiftEndSec - $shiftStartSec) / 86400) * 100;
                        @endphp

                        @if($showBackgrounds)
                            <div
                                class="absolute rounded z-10 border border-gray-300 dark:border-gray-600"
                                style="
                                top: {{ $groupTop }}px;
                                left: {{ $groupLeftPercent }}%;
                                width: {{ max($groupWidthPercent, 2) }}%;
                                height: {{ $groupHeight }}px;
                                background-color: {{ $groupColor }};
                                opacity: 0.95;
                                border-radius: 4px;
                            "
                            ></div>
                        @endif

                        @foreach ($groupInfo['regions'] as $availability)
                            @php
                                $availStart = Carbon::parse($availability['start'])->tz(config('app.timezone'));
                                $availEnd = Carbon::parse($availability['end'])->tz(config('app.timezone'));
                                $availStartSec = $availStart->secondsSinceMidnight();
                                $availEndSec = $availEnd->secondsSinceMidnight();
                                $availLeftPercent = ($availStartSec / 86400) * 100;
                                $availWidthPercent = (($availEndSec - $availStartSec) / 86400) * 100;
                                $regionId = $availability['region_id'] ?? 'unknown';
                                $availColor = $legendColorMap[$regionId] ?? '#ccc';
                                $regionDescription = $availability['region_description'] ?? 'Unknown region';

                                // Get the line index for this region ID from the prepared line map
                                $lineIndex = $groupInfo['lineMap'][$regionId] ?? 0;

                                // Calculate the total height of all region bars in this group
                                $totalRegionsHeight = count($groupInfo['lineMap']) * $regionBarHeight;

                                // Calculate the vertical padding needed to center the bars
                                $verticalPadding = ($groupHeight - 6 - $totalRegionsHeight) / 2; // Subtract 6px for the group label space

                                // Add the padding to the top position
                                $regionTop = $groupTop + 3 + $verticalPadding + ($lineIndex * $regionBarHeight);

                                $style = "top: {$regionTop}px; left: {$availLeftPercent}%; width: " . max($availWidthPercent, 1) . "%; background-color: {$availColor};";
                                if (isset($availability['region_active']) && !$availability['region_active']) {
                                    $style .= " background-image: repeating-linear-gradient(45deg, {$availColor}, {$availColor} 4px, rgba(255,255,255,0.5) 4px, rgba(255,255,255,0.5) 8px); background-blend-mode: multiply;";
                                }
                                $zIndex = 20 + ($availability['override_priority'] ?? 0);
                            @endphp

                            <div
                                class="absolute h-1.5 rounded-sm opacity-90 cursor-pointer"
                                style="{{ $style }} z-index: {{ $zIndex }};"
                                @mouseenter="showRegionTooltip($event, '{{ $regionDescription }}', '{{ $availStart->format('Y-m-d H:i') }}', '{{ $availEnd->format('Y-m-d H:i') }}', {{ $availability['region_active'] ? 'true' : 'false' }})"
                                @mouseleave="hideTooltip()"
                            ></div>
                        @endforeach
                    @endforeach
                    @if ($stackCount > 0)
                        <div class="absolute left-0 right-0 bottom-0" style="height: 8px;"></div>
                    @endif
                    <div id="tooltip-portal" class="fixed inset-0 pointer-events-none" style="z-index: 99999;"
                         x-ref="tooltipPortal"></div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<script>
    // Removed the ganttTooltips function since we're using inline Alpine.js data
</script>
