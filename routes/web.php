<?php

use App\Http\Controllers\Exports\ExportFileDownloadController;
use App\Http\Controllers\ToolRunPageController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::get('/exports/{exportFile}/download', ExportFileDownloadController::class)
    ->middleware(['web', Authenticate::class])
    ->whereUuid('exportFile')
    ->name('exports.download');

Route::middleware(['web', Authenticate::class])
    ->prefix('{scopeType}/{scopeId}/runs/{run}')
    ->where(['scopeType' => 'datasets|unfiled'])
    ->controller(ToolRunPageController::class)
    ->group(static function (): void {
        Route::get('page', 'page')->name('tool-runs.page');
        Route::get('download/html', 'downloadHtml')->name('tool-runs.download-html');
        Route::get('download/json', 'downloadJson')->name('tool-runs.download-json');
        Route::get('download/csv', 'downloadCsv')->name('tool-runs.download-csv');
        Route::get('download/booking-csv', 'downloadBookingCsv')->name('tool-runs.download-booking-csv');
        Route::get('download/history-csv', 'downloadHistoryCsv')->name('tool-runs.download-history-csv');
    });
