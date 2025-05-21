<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;

class ResourceServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Register the views for filament infolists
        $this->loadViewsFrom(resource_path('views/filament/infolists/components'), 'filament-infolists');

        // Register JavaScript assets for charts and maps
        FilamentAsset::register([
            // ApexCharts for data visualization
            Js::make('apexcharts', 'https://cdn.jsdelivr.net/npm/apexcharts@3.35.3/dist/apexcharts.min.js'),

            // Google Maps API
            Js::make('google-maps', 'https://maps.googleapis.com/maps/api/js?key=' . config('services.google_maps.key') . '&libraries=places'),
        ]);

        // Add configuration for Google Maps API key
        config([
            'services.google_maps.key' => env('GOOGLE_MAPS_API_KEY', ''),
        ]);

        // Define custom blade directive for utilization percentage
        Blade::directive('utilColor', function ($expression) {
            return "<?php echo floatval({$expression}) < 25 ? 'bg-red-500' : (floatval({$expression}) < 50 ? 'bg-yellow-500' : (floatval({$expression}) < 75 ? 'bg-blue-500' : 'bg-green-500')); ?>";
        });
    }
}
