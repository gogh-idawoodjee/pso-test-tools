<?php

namespace App\Providers;

use App\Enums\Tool as ToolId;
use App\Models\ExternalSanctumToken;
use App\Tools\ActivityTrace\ActivityTraceTool;
use App\Tools\LoadAudit\LoadAuditTool;
use App\Tools\PlanTravels\PlanTravelsTool;
use App\Tools\RegionAvailability\RegionAvailabilityTool;
use App\Tools\ToolRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Each tool port registers its implementation on this registry.
        $this->app->singleton(ToolRegistry::class);
        $this->app->afterResolving(ToolRegistry::class, static function (ToolRegistry $registry): void {
            $registry->register(ToolId::LoadAudit, LoadAuditTool::class);
            $registry->register(ToolId::RegionAvailability, RegionAvailabilityTool::class);
            $registry->register(ToolId::AppointmentTimeline, ActivityTraceTool::class);
            $registry->register(ToolId::PlanTravels, PlanTravelsTool::class);
        });

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {

            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);

            $this->app->register(TelescopeServiceProvider::class);

        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        Model::unguard();
        Sanctum::usePersonalAccessTokenModel(ExternalSanctumToken::class);

        if (app()->environment('production')) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }

        if (app()->runningInConsole() && app()->runningUnitTests() === false) {
            ini_set('max_execution_time', 0);
        }

        Health::checks([
            OptimizedAppCheck::new(),
            DebugModeCheck::new(),
            EnvironmentCheck::new(),
            UsedDiskSpaceCheck::new(),
        ]);

        Gate::define('viewLogViewer', function ($user) {
            return str_ends_with($user->email, '@mac.com')
                || str_ends_with($user->email, '@goghsolutions.com')
                || str_ends_with($user->email, '@thetechnodro.me');
        });
    }
}
