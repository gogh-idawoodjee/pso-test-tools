<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Settings\AppSettings;
use Illuminate\Support\Facades\Cache;

it('returns the shipped defaults when nothing is saved', function () {
    $settings = app(AppSettings::class);
    expect($settings->noiseEventIds())->toBe('3041,1767,2959,1964,1965,24578')
        ->and($settings->completeGraceHours())->toBe(24)
        ->and($settings->flagActivities())->toBe(5)
        ->and($settings->flagDeletions())->toBe(200)
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

it('lets a cached snapshot expire, so one filled just after a write cannot outlive it for good', function () {
    $settings = app(AppSettings::class);
    $settings->put(AppSettings::NOISE_EVENT_IDS, '1');
    expect($settings->noiseEventIds())->toBe('1');

    // A write that lands without forgetting the cache, as a racing read would leave it.
    AppSetting::query()->whereKey(AppSettings::NOISE_EVENT_IDS)->update(['value' => json_encode('2')]);
    expect($settings->noiseEventIds())->toBe('1');

    $this->travel(AppSettings::CACHE_SECONDS + 1)->seconds();
    expect($settings->noiseEventIds())->toBe('2');
});

it('rejects a key it does not know', function () {
    app(AppSettings::class)->get('nope');
})->throws(InvalidArgumentException::class);
