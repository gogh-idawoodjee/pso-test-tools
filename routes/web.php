<?php

use App\Http\Controllers\ToolRunPageController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::get('/download/{filename}', static function (string $filename) {
    $root = realpath(storage_path('app/public'));
    $path = realpath(storage_path('app/public/'.$filename));

    if ($root === false || $path === false || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
        abort(404);
    }

    return response()->download($path, $filename, [
        'Content-Type' => str_ends_with($filename, '.zip') ? 'application/zip' : 'application/json',
    ]);
})->middleware(['web', Authenticate::class])->name('download.filtered')->where('filename', '[A-Za-z0-9._-]+');

Route::middleware(['web', Authenticate::class])
    ->prefix('datasets/{dataset}/runs/{run}')
    ->controller(ToolRunPageController::class)
    ->group(static function (): void {
        Route::get('page', 'page')->name('tool-runs.page');
        Route::get('download/html', 'downloadHtml')->name('tool-runs.download-html');
        Route::get('download/json', 'downloadJson')->name('tool-runs.download-json');
        Route::get('download/booking-csv', 'downloadBookingCsv')->name('tool-runs.download-booking-csv');
        Route::get('download/history-csv', 'downloadHistoryCsv')->name('tool-runs.download-history-csv');
    });
