<?php

namespace App\Providers\Filament;

use App\Filament\Navigation\Groups;
use App\Filament\Navigation\LivePsoActionPages;
use App\Filament\Pages\Backups;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\HealthCheckResults;
use App\Http\Controllers\Exports\ExportCompleteController;
use App\Http\Controllers\Exports\ExportSlotController;
use App\Http\Controllers\SysCompare\SysCompareDownloadController;
use App\Http\Controllers\SysCompare\SysCompareUploadController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use pxlrbt\FilamentEnvironmentIndicator\EnvironmentIndicatorPlugin;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin;
use ShuvroRoy\FilamentSpatieLaravelHealth\FilamentSpatieLaravelHealthPlugin;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('/')
            ->login()
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups(Groups::ordered())
            ->brandLogoHeight('auto')
            ->brandLogo(asset('images/pso_test_tools_logo_final.svg'))
            ->favicon(asset('images/favicon.png'))
            ->colors([
                'primary' => Color::Violet,
                'gray' => Color::Slate,
                'analyse' => Color::Cyan,
                'live' => Color::Rose,
                'setup' => Color::Amber,
                'admin' => Color::Emerald,
            ])
            ->viteTheme('resources/css/filament/app/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->plugins([
                FilamentSpatieLaravelBackupPlugin::make()
                    ->usingPage(Backups::class),
                EnvironmentIndicatorPlugin::make()->showGitBranch(),
                FilamentSpatieLaravelHealthPlugin::make()
                    ->usingPage(HealthCheckResults::class),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authenticatedRoutes(function (): void {
                // PSO Sys File Compare: sys files hold customer data, so they bypass Livewire's
                // R2-backed temporary uploads and go to a private local disk instead.
                Route::post('sys-compare/uploads', SysCompareUploadController::class)
                    ->middleware('throttle:60,1')
                    ->name('sys-compare.uploads.store');
                Route::get('sys-compare/runs/{run}/{artifact}', SysCompareDownloadController::class)
                    ->name('sys-compare.runs.download');

                // Export sets: the browser uploads straight to R2 through a presigned PUT url.
                Route::post('exports/slots', ExportSlotController::class)
                    ->middleware('throttle:60,1')
                    ->name('exports.slots.store');
                Route::post('exports/{exportFile}/complete', ExportCompleteController::class)
                    ->middleware('throttle:60,1')
                    ->name('exports.complete');
            });
    }

    public function register(): void
    {
        parent::register();
        FilamentView::registerRenderHook('panels::body.end',
            fn (): string => Blade::render("@vite('resources/js/app.js')"));
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            fn (): string => view('filament.live-environment-banner')->render(),
            scopes: LivePsoActionPages::all(),
        );

    }
}
