<?php

use App\Models\User;
use App\Support\Settings\AppSettings;
use Illuminate\Support\Facades\Cache;

it('returns the shipped defaults when nothing is saved', function () {
    $settings = app(AppSettings::class);
    expect($settings->noiseEventIds())->toBe('3041,1767,2959,1964,1965,24578')
        ->and($settings->completeGraceHours())->toBe(24)
        ->and($settings->flagActivities())->toBe(5)
        ->and($settings->flagDeletions())->toBe(30)
        ->and($settings->messageTexts())->toBeNull()
        ->and($settings->paramDefinitions())->toBeNull();
});

it('returns the saved value, typed and normalised', function () {
    $user = User::factory()->create(['name' => 'Ada']);
    app(AppSettings::class)->saveLoadAuditDefaults(' 3041, 24578 ', 12, 7, 40, $user->id);
    $settings = app(AppSettings::class);
    expect($settings->noiseEventIds())->toBe('3041,24578')
        ->and($settings->completeGraceHours())->toBe(12)
        ->and($settings->updatedByName(AppSettings::NOISE_EVENT_IDS))->toBe('Ada')
        ->and($settings->updatedAt(AppSettings::NOISE_EVENT_IDS))->not->toBeNull();
});

it('caches reads and forgets the cache on every write', function () {
    $settings = app(AppSettings::class);
    $settings->noiseEventIds();
    expect(Cache::has(AppSettings::CACHE_KEY))->toBeTrue();
    $settings->put(AppSettings::NOISE_EVENT_IDS, '1');
    expect(Cache::has(AppSettings::CACHE_KEY))->toBeFalse()->and($settings->noiseEventIds())->toBe('1');
    $settings->forget(AppSettings::NOISE_EVENT_IDS);
    expect($settings->noiseEventIds())->toBe('3041,1767,2959,1964,1965,24578');
});

it('rejects a key it does not know', function () {
    app(AppSettings::class)->get('nope');
})->throws(InvalidArgumentException::class);
