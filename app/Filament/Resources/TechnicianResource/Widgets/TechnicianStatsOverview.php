<?php

namespace App\Filament\Widgets;

use App\Models\Technician;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class TechnicianStatsOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        // Get today's date
        $today = now()->format('M j, Y');

        // Count technicians with shifts today
        $techsWithShiftsToday = Technician::whereJsonContains('shifts->shifts', ['shift_date' => $today])->count();

        // Get total number of technicians
        $totalTechnicians = Technician::count();

        // Calculate average utilization across all technicians
        $avgUtilization = Technician::all()->avg(function ($technician) {
            return $technician->average_utilization;
        });

        // Count total shifts for current month
        $currentMonth = now()->format('M');
        $totalShiftsThisMonth = Technician::all()->sum(function ($technician) use ($currentMonth) {
            $shiftsThisMonth = collect($technician->shifts['shifts'] ?? [])
                ->filter(function ($shift) use ($currentMonth) {
                    // Check if shift date contains current month name
                    return strpos($shift['shift_date'], $currentMonth) !== false;
                });

            return $shiftsThisMonth->count();
        });

        // Get top skills by count
        $topSkills = DB::table('technicians')
            ->select(DB::raw('JSON_EXTRACT(skills, "$[*].description") as skill_descriptions'))
            ->get()
            ->flatMap(function ($row) {
                $descriptions = json_decode($row->skill_descriptions, true);
                return is_array($descriptions) ? $descriptions : [];
            })
            ->countBy()
            ->sortDesc()
            ->take(3)
            ->map(function ($count, $skill) {
                return "$skill ($count)";
            })
            ->implode(', ');

        return [
            Stat::make('Active Technicians Today', $techsWithShiftsToday)
                ->description($techsWithShiftsToday > 0 ? 'Technicians with shifts today' : 'No technicians scheduled today')
                ->descriptionIcon($techsWithShiftsToday > 0 ? 'heroicon-m-calendar' : 'heroicon-m-x-mark')
                ->color($techsWithShiftsToday > 0 ? 'success' : 'danger'),

            Stat::make('Average Utilization', number_format($avgUtilization, 1) . '%')
                ->description($avgUtilization >= 50 ? 'Good utilization rate' : 'Low utilization rate')
                ->descriptionIcon($avgUtilization >= 50 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($avgUtilization >= 75 ? 'success' : ($avgUtilization >= 50 ? 'warning' : 'danger')),

            Stat::make('Total Shifts This Month', $totalShiftsThisMonth)
                ->description('For ' . now()->format('F Y'))
                ->descriptionIcon('heroicon-m-calendar')
                ->color('primary'),

            Stat::make('Top Skills', $topSkills)
                ->description('Most common technician skills')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('success'),
        ];
    }
}
