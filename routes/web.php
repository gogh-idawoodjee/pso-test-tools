<?php

use App\Http\Controllers\ToolRunPageController;
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
    ->controller(ToolRunPageController::class)
    ->group(static function (): void {
        Route::get('page', 'page')->name('tool-runs.page');
        Route::get('download/html', 'downloadHtml')->name('tool-runs.download-html');
        Route::get('download/json', 'downloadJson')->name('tool-runs.download-json');
    });
