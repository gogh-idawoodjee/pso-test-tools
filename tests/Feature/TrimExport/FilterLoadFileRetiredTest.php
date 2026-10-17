<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('retires Filter Load File and its public download', function () {
    expect(Route::has('download.filtered'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Pages\\FilterLoadFile'))->toBeFalse()
        ->and(class_exists('App\\Services\\ResourceActivityFilterService'))->toBeFalse()
        ->and(config('filesystems.links'))->toBe([]);

    $this->actingAs(User::factory()->create())->get('/download/filtered_x.json')->assertNotFound();
});
