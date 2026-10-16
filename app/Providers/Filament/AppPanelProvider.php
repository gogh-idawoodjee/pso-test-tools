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
use Filament\Auth\Pages\Login;
use Filament\FontProviders\GoogleFontProvider;
use Filament\FontProviders\LocalFontProvider;
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
    /** Anton (display), IBM Plex Sans (UI) and IBM Plex Mono (IDs, counts, files), per docs/pso-theme/CLAUDE-THEME.md. */
    public const FONTS_URL = 'https://fonts.googleapis.com/css2?family=Anton&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap';

    /**
     * The PSO theme palettes. Primary runs from the tab tint through iris (#8567DA, 500) and the link
     * violet (#5B3FBF, 600) to brand violet (#2A1B4F, 900). Gray is warm bone in the light shades and the
     * dark-mode violet surfaces in the deep ones (#1C1433 cards, #120C24 page).
     *
     * @return array<string, array<int, string>>
     */
    public static function colors(): array
    {
        $palette = static fn (array $hex): array => array_map(Color::convertToOklch(...), $hex);

        return [
            'primary' => $palette([
                50 => '#F7F4FE', 100 => '#EFEAFB', 200 => '#DDD3F7', 300 => '#C5B5F0', 400 => '#B9A4F5',
                500 => '#8567DA', 600 => '#5B3FBF', 700 => '#4A31A3', 800 => '#3A2869', 900 => '#2A1B4F', 950 => '#1C1236',
            ]),
            'gray' => $palette([
                50 => '#FBF9F5', 100 => '#F6F3EC', 200 => '#E7E1D6', 300 => '#D3CBBB', 400 => '#A39DAF',
                500 => '#6B6578', 600 => '#524C61', 700 => '#3D3654', 800 => '#2E2552', 900 => '#1C1433', 950 => '#120C24',
            ]),
            'success' => Color::hex('#1C7C45'),
            'warning' => Color::hex('#A15C07'),
            'danger' => Color::hex('#B42318'),
            'info' => Color::hex('#5B3FBF'),
        ];
    }

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
            ->brandLogo(fn () => view('filament.brand'))
            ->favicon(asset('favicon.svg'))
            // IBM Plex Sans for the UI; the one Google Fonts link also loads Anton and IBM Plex Mono.
            ->font('IBM Plex Sans', url: self::FONTS_URL, provider: GoogleFontProvider::class)
            ->monoFont('IBM Plex Mono', provider: LocalFontProvider::class)
            ->colors(self::colors())
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
        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_END,
            fn (): string => view('filament.head')->render());
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIMPLE_PAGE_START,
            fn (): string => view('filament.login-lockup')->render(),
            scopes: Login::class,
        );

    }
}
