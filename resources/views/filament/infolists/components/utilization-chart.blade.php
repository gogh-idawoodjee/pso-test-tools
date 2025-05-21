@php
    $shifts = $getRecord()->shifts['shifts'] ?? [];

    // Sort shifts by date
    usort($shifts, function($a, $b) {
        $dateA = \Carbon\Carbon::parse($a['shift_date']);
        $dateB = \Carbon\Carbon::parse($b['shift_date']);
        return $dateA->timestamp - $dateB->timestamp;
    });

    // Extract data for chart
    $dates = [];
    $utilization = [];
    $allocations = [];
    $unutilizedTime = [];

    foreach ($shifts as $shift) {
        $dates[] = $shift['shift_date'];
        $utilization[] = floatval($shift['utilisation']['percent']);
        $allocations[] = intval($shift['utilisation']['total_allocations']);

        // Convert unutilized time from string to hours
        $timeStr = $shift['utilisation']['total_unutilised_time'];
        preg_match('/(\d+)\s+hours(?:\s+(\d+)\s+minutes(?:\s+(\d+)\s+seconds)?)?/', $timeStr, $matches);

        $hours = isset($matches[1]) ? intval($matches[1]) : 0;
        $minutes = isset($matches[2]) ? intval($matches[2]) / 60 : 0;
        $seconds = isset($matches[3]) ? intval($matches[3]) / 3600 : 0;

        $unutilizedTime[] = $hours + $minutes + $seconds;
    }

    // Calculate statistics
    $avgUtilization = !empty($utilization) ? array_sum($utilization) / count($utilization) : 0;
    $maxUtilization = !empty($utilization) ? max($utilization) : 0;
    $minUtilization = !empty($utilization) ? min($utilization) : 0;
    $totalAllocations = array_sum($allocations);
    $avgAllocations = !empty($allocations) ? $totalAllocations / count($allocations) : 0;

    // Convert data to JSON for chart
    $chartData = [];
    for ($i = 0; $i < count($dates); $i++) {
        $chartData[] = [
            'date' => $dates[$i],
            'utilization' => $utilization[$i],
            'allocations' => $allocations[$i],
            'unutilizedHours' => $unutilizedTime[$i]
        ];
    }
    $chartDataJson = json_encode($chartData);
@endphp

<div class="space-y-6">
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm font-medium text-gray-500">Average Utilization</div>
            <div class="mt-1 flex items-baseline">
                <div class="text-2xl font-semibold text-gray-900">{{ number_format($avgUtilization, 1) }}%</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm font-medium text-gray-500">Max Utilization</div>
            <div class="mt-1 flex items-baseline">
                <div class="text-2xl font-semibold text-gray-900">{{ number_format($maxUtilization, 1) }}%</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm font-medium text-gray-500">Total Allocations</div>
            <div class="mt-1 flex items-baseline">
                <div class="text-2xl font-semibold text-gray-900">{{ $totalAllocations }}</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm font-medium text-gray-500">Avg Allocations per Shift</div>
            <div class="mt-1 flex items-baseline">
                <div class="text-2xl font-semibold text-gray-900">{{ number_format($avgAllocations, 1) }}</div>
            </div>
        </div>
    </div>

    <!-- Utilization Chart -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="text-base font-medium text-gray-700 mb-4">Utilization Over Time</h3>
        <div id="utilization-chart" class="h-80"></div>
    </div>

    <!-- Allocations Chart -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="text-base font-medium text-gray-700 mb-4">Allocations vs Unutilized Time</h3>
        <div id="allocations-chart" class="h-80"></div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const chartData = {!! $chartDataJson !!};

        // Utilization Chart
        const utilizationChart = new ApexCharts(document.querySelector('#utilization-chart'), {
            series: [{
                name: 'Utilization (%)',
                data: chartData.map(item => item.utilization)
            }],
            chart: {
                type: 'area',
                height: 320,
                toolbar: {
                    show: true
                }
            },
            dataLabels: {
                enabled: false
            },
            stroke: {
                curve: 'smooth',
                width: 2
            },
            xaxis: {
                categories: chartData.map(item => item.date),
                labels: {
                    rotate: -45,
                    style: {
                        fontSize: '10px'
                    }
                }
            },
            yaxis: {
                title: {
                    text: 'Utilization (%)'
                },
                min: 0,
                max: 100
            },
            tooltip: {
                y: {
                    formatter: function(value) {
                        return value.toFixed(1) + '%';
                    }
                }
            },
            colors: ['#3b82f6'],
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.7,
                    opacityTo: 0.2,
                    stops: [0, 90, 100]
                }
            }
        });
        utilizationChart.render();

        // Allocations vs Unutilized Time Chart
        const allocationsChart = new ApexCharts(document.querySelector('#allocations-chart'), {
            series: [{
                name: 'Allocations',
                type: 'column',
                data: chartData.map(item => item.allocations)
            }, {
                name: 'Unutilized Hours',
                type: 'line',
                data: chartData.map(item => item.unutilizedHours)
            }],
            chart: {
                height: 320,
                type: 'line',
                toolbar: {
                    show: true
                }
            },
            stroke: {
                width: [0, 3]
            },
            dataLabels: {
                enabled: false
            },
            xaxis: {
                categories: chartData.map(item => item.date),
                labels: {
                    rotate: -45,
                    style: {
                        fontSize: '10px'
                    }
                }
            },
            yaxis: [{
                title: {
                    text: 'Allocations',
                },
                min: 0
            }, {
                opposite: true,
                title: {
                    text: 'Unutilized Hours'
                },
                min: 0
            }],
            tooltip: {
                shared: true,
                intersect: false
            },
            colors: ['#10b981', '#f59e0b']
        });
        allocationsChart.render();
    });
</script>
