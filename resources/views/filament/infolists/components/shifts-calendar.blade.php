@php
    $shifts = $getRecord()->shifts['shifts'] ?? [];
    $currentMonth = date('n'); // Current month (1-12)
    $currentYear = date('Y'); // Current year

    // Group shifts by month and year
    $groupedShifts = [];
    foreach ($shifts as $shift) {
        $date = \Carbon\Carbon::parse($shift['shift_date']);
        $month = $date->format('n');
        $year = $date->format('Y');

        if (!isset($groupedShifts["$year-$month"])) {
            $groupedShifts["$year-$month"] = [];
        }

        $groupedShifts["$year-$month"][] = $shift;
    }

    // Sort by date
    ksort($groupedShifts);

    // Function to get shifts for a specific day
    function getShiftForDay($shifts, $day, $month, $year) {
        foreach ($shifts as $shift) {
            $date = \Carbon\Carbon::parse($shift['shift_date']);
            if ($date->day == $day && $date->month == $month && $date->year == $year) {
                return $shift;
            }
        }
        return null;
    }

    // Get the current month's data or the first available month
    $displayMonth = $currentMonth;
    $displayYear = $currentYear;

    if (!empty($groupedShifts)) {
        $firstKey = array_key_first($groupedShifts);
        list($firstYear, $firstMonth) = explode('-', $firstKey);

        // If current month has no shifts, use the first available month
        if (!isset($groupedShifts["$displayYear-$displayMonth"])) {
            $displayYear = $firstYear;
            $displayMonth = $firstMonth;
        }
    }

    $monthName = date('F', mktime(0, 0, 0, $displayMonth, 1, $displayYear));
    $daysInMonth = date('t', mktime(0, 0, 0, $displayMonth, 1, $displayYear));
    $firstDayOfMonth = date('N', mktime(0, 0, 0, $displayMonth, 1, $displayYear));
@endphp

<div class="bg-white rounded-lg shadow">
    <div class="p-4 flex items-center justify-between bg-gray-50 rounded-t-lg">
        <h2 class="text-lg font-semibold text-gray-700">{{ $monthName }} {{ $displayYear }}</h2>
        <div class="flex space-x-2">
            <button id="prev-month" class="p-2 rounded-lg hover:bg-gray-200">
                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
            </button>
            <button id="today" class="px-3 py-1 text-sm bg-blue-500 text-white rounded-lg">Today</button>
            <button id="next-month" class="p-2 rounded-lg hover:bg-gray-200">
                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </button>
        </div>
    </div>

    <div class="p-4">
        <div class="grid grid-cols-7 gap-2 mb-2">
            <div class="text-center text-sm font-medium text-gray-600">Mon</div>
            <div class="text-center text-sm font-medium text-gray-600">Tue</div>
            <div class="text-center text-sm font-medium text-gray-600">Wed</div>
            <div class="text-center text-sm font-medium text-gray-600">Thu</div>
            <div class="text-center text-sm font-medium text-gray-600">Fri</div>
            <div class="text-center text-sm font-medium text-gray-600">Sat</div>
            <div class="text-center text-sm font-medium text-gray-600">Sun</div>
        </div>

        <div class="grid grid-cols-7 gap-2">
            @php
                // Add empty cells for the days before the first day of the month
                for ($i = 1; $i < $firstDayOfMonth; $i++) {
                    echo '<div class="p-2 h-24 bg-gray-50 rounded-lg"></div>';
                }

                // Add cells for each day of the month
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $isToday = $day == date('j') && $displayMonth == date('n') && $displayYear == date('Y');
                    $shift = getShiftForDay($groupedShifts["$displayYear-$displayMonth"] ?? [], $day, $displayMonth, $displayYear);
                    $hasShift = $shift !== null;

                    echo '<div class="p-2 h-24 border ' . ($isToday ? 'border-blue-500 bg-blue-50' : 'border-gray-200') . ' rounded-lg relative overflow-hidden">';
                    echo '<div class="text-right ' . ($isToday ? 'font-bold text-blue-600' : 'text-gray-700') . '">' . $day . '</div>';

                    if ($hasShift) {
                        $utilPercent = floatval($shift['utilisation']['percent']);
                        $colorClass = $utilPercent < 25 ? 'bg-red-500' : ($utilPercent < 50 ? 'bg-yellow-500' : ($utilPercent < 75 ? 'bg-blue-500' : 'bg-green-500'));

                        echo '<div class="mt-1">';
                        echo '<div class="text-xs font-medium">' . $shift['shift_span'] . '</div>';
                        echo '<div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">';
                        echo '<div class="' . $colorClass . ' h-1.5 rounded-full" style="width: ' . $utilPercent . '%"></div>';
                        echo '</div>';
                        echo '<div class="text-xs mt-1 truncate">' . $shift['utilisation']['total_allocations'] . ' allocations</div>';
                        echo '</div>';
                    }

                    echo '</div>';
                }

                // Add empty cells for the days after the last day of the month
                $remainingCells = 7 - (($firstDayOfMonth - 1 + $daysInMonth) % 7);
                if ($remainingCells < 7) {
                    for ($i = 0; $i < $remainingCells; $i++) {
                        echo '<div class="p-2 h-24 bg-gray-50 rounded-lg"></div>';
                    }
                }
            @endphp
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Calendar navigation would be implemented here
        // For a real implementation, we would use AJAX to load new month data

        // Placeholder functions for the buttons
        document.getElementById('prev-month').addEventListener('click', function() {
            alert('Navigate to previous month (would use AJAX in a real implementation)');
        });

        document.getElementById('next-month').addEventListener('click', function() {
            alert('Navigate to next month (would use AJAX in a real implementation)');
        });

        document.getElementById('today').addEventListener('click', function() {
            alert('Navigate to current month (would use AJAX in a real implementation)');
        });
    });
</script>
