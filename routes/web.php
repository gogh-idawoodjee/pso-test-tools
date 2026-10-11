<?php

use App\Http\Controllers\LoadAuditRunController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::get('/download/{filename}', static function ($filename) {
    $path = storage_path('app/public/'.$filename);

    if (! file_exists($path)) {
        abort(404);
    }

    return response()->download($path, $filename, [
        'Content-Type' => 'application/json',
    ]);
})->name('download.filtered')->where('filename', '.*');

Route::middleware(['web', Authenticate::class])
    ->prefix('datasets/{dataset}/runs/{run}')
    ->controller(LoadAuditRunController::class)
    ->group(static function (): void {
        Route::get('dashboard', 'dashboard')->name('load-audit.dashboard');
        Route::get('download/html', 'downloadHtml')->name('load-audit.download-html');
        Route::get('download/findings', 'downloadFindings')->name('load-audit.download-findings');
    });
