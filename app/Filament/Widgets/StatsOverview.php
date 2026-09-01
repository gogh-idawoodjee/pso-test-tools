<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\EnvironmentResource;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\TokenUsageLog;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\App;
use Spatie\Health\ResultStores\ResultStore;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = -1;

    protected function getStats(): array
    {
        return [
            Stat::make('Environments', Environment::count())
                ->description('Configured PSO environments')
                ->descriptionIcon(Heroicon::OutlinedCircleStack)
                ->icon(Heroicon::OutlinedCircleStack)
                ->color('base-data')
                ->url(EnvironmentResource::getUrl()),

            Stat::make('Datasets', Dataset::count())
                ->description('Saved datasets across environments')
                ->descriptionIcon(Heroicon::OutlinedRectangleStack)
                ->icon(Heroicon::OutlinedRectangleStack)
                ->color('base-data'),

            Stat::make('API Calls (24h)', TokenUsageLog::where('created_at', '>=', now()->subDay())->count())
                ->description('Requests logged in the last 24 hours')
                ->descriptionIcon(Heroicon::OutlinedBolt)
                ->icon(Heroicon::OutlinedBolt)
                ->color('core'),

            $this->healthStat(),
        ];
    }

    private function healthStat(): Stat
    {
        $results = App::make(ResultStore::class)->latestResults();

        if ($results === null) {
            return Stat::make('System Health', 'Not yet run')
                ->description('No health checks have been recorded')
                ->descriptionIcon(Heroicon::OutlinedQuestionMarkCircle)
                ->icon(Heroicon::OutlinedCpuChip)
                ->color('gray');
        }

        $isHealthy = $results->allChecksOk();

        return Stat::make('System Health', $isHealthy ? 'Healthy' : 'Attention needed')
            ->description('As of '.$results->finishedAt->diffForHumans())
            ->descriptionIcon($isHealthy ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
            ->icon(Heroicon::OutlinedCpuChip)
            ->color($isHealthy ? 'success' : 'danger');
    }
}
