<?php

use Illuminate\Support\Facades\Route;

Route::get('/download/{filename}', static function ($filename) {
    $path = storage_path('app/public/' . $filename);

    if (!file_exists($path)) {
        abort(404);
    }

    return response()->download($path, $filename, [
        'Content-Type' => 'application/json',
    ]);
})->name('download.filtered')->where('filename', '.*');
