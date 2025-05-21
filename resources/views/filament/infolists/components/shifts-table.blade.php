@php
    $shifts = $getRecord()->shifts['shifts'] ?? [];

    // Sort shifts by date
    usort($shifts, function($a, $b) {
        $dateA = \Carbon\Carbon::parse($a['shift_date']);
        $dateB = \Carbon\Carbon::parse($b['shift_date']);
        return $dateA->timestamp - $dateB->timestamp;
    });

    // Get current date for highlighting
    $today = \Carbon\Carbon::now()->format('M j, Y');

    // Get shifts from today and future
    $upcomingShifts = array_filter($shifts, function($shift) use ($today) {
        $shiftDate = \Carbon\Carbon::parse($shift['shift_date']);
        return $shiftDate->timestamp >= \Carbon\Carbon::now()->startOfDay()->timestamp;
    });

    // Limit to next 10 shifts
    $upcomingShifts = array_slice($upcomingShifts, 0, 10);
@endphp

<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="flex justify-between items-center p-4 bg-gray-50">
        <h3 class="text-base font-medium text-gray-700">Upcoming Shifts</h3>
        <select id="shift-filter" class="text-sm border-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring-primary-500">
            <option value="upcoming">Upcoming</option>
            <option value="all">All Shifts</option>
            <option value="high-util">High Utilization</option>
            <option value="low-util">Low Utilization</option>
        </select>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
            <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Utilization</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Allocations</th>
                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200" id="shifts-table-body">
            @forelse($upcomingShifts as $shift)
                @php
                    $utilPercent = floatval($shift['utilisation']['percent']);
                    $utilColor = $utilPercent < 25 ? 'bg-red-500' : ($utilPercent < 50 ? 'bg-yellow-500' : ($utilPercent < 75 ? 'bg-blue-500' : 'bg-green-500'));
                    $isToday = $shift['shift_date'] === $today;
                @endphp
                <tr class="{{ $isToday ? 'bg-blue-50' : '' }} hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="text-sm font-medium text-gray-900">
                                {{ $shift['shift_date'] }}
                            </div>
                            @if($isToday)
                                <span class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        Today
                                    </span>
                            @endif
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ $shift['shift_span'] }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ $shift['shift_duration'] }} hours</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="mr-2 w-20 bg-gray-200 rounded-full h-2.5">
                                <div class="{{ $utilColor }} h-2.5 rounded-full" style="width: {{ min($utilPercent, 100) }}%"></div>
                            </div>
                            <span class="text-sm text-gray-900">{{ number_format($utilPercent, 1) }}%</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {{ $shift['utilisation']['total_allocations'] }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button type="button" class="text-indigo-600 hover:text-indigo-900 view-shift" data-id="{{ $shift['id'] }}">
                            View
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                        No upcoming shifts found
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
        <div class="text-sm text-gray-700">
            Total shifts: <span class="font-medium">{{ $getRecord()->shifts['total_shifts'] ?? 0 }}</span>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const viewButtons = document.querySelectorAll('.view-shift');
        viewButtons.forEach(button => {
            button.addEventListener('click', function() {
                const shiftId = this.getAttribute('data-id');
                // In a real app, this would open a modal or navigate to a shift detail page
                alert(`View shift details for ID: ${shiftId}`);
            });
        });

        // Filter functionality would be implemented here
        // For a real implementation, we would use AJAX to load filtered data

        document.getElementById('shift-filter').addEventListener('change', function() {
            alert(`Filter shifts by: ${this.value} (would use AJAX in a real implementation)`);
        });
    });
</script>
