<?php

use App\Http\Controllers\TravelCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/travel/callback', TravelCallbackController::class)->name('travel.callback');
