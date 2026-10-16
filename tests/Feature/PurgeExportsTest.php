<?php

use App\Enums\ExportFileStatus;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid(), 'exports.scratch_sweep_hours' => 2]);
});

it('deletes expired objects and marks rows purged, leaving live ones alone', function () {
    $storage = app(ExportStorage::class);
    $expired = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['expires_at' => now()->subMinute()]);
    $live = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['expires_at' => now()->addHour()]);
    Storage::disk('r2')->put($storage->objectKey($expired), 'x');
    Storage::disk('r2')->put($storage->objectKey($live), 'y');

    Artisan::call('exports:purge');

    expect($expired->refresh()->status)->toBe(ExportFileStatus::Purged)
        ->and($storage->exists($expired))->toBeFalse()
        ->and($live->refresh()->status)->toBe(ExportFileStatus::Ready)
        ->and($storage->exists($live))->toBeTrue();
});

it('purges an expired slot that never received an object without error', function () {
    $ghost = ExportFile::factory()->for(Dataset::factory(), 'scope')->uploading()->create(['expires_at' => now()->subMinute()]);

    Artisan::call('exports:purge');

    expect($ghost->refresh()->status)->toBe(ExportFileStatus::Purged);
});

it('sweeps scratch folders older than the sweep window', function () {
    $root = config('exports.scratch_path');
    mkdir("$root/old", 0770, true);
    touch("$root/old/file.xml", time() - 3 * 3600);
    touch("$root/old", time() - 3 * 3600);
    mkdir("$root/new", 0770, true);
    touch("$root/new/file.xml");

    Artisan::call('exports:purge');

    expect(is_dir("$root/old"))->toBeFalse()->and(is_dir("$root/new"))->toBeTrue();
});

it('marks an upload that never finished as failed once the slot and its grace have passed', function () {
    config(['exports.slot_minutes' => 15]);
    $stale = ExportFile::factory()->for(Dataset::factory(), 'scope')->uploading()->create(['expires_at' => now()->addHours(10)]);
    ExportFile::query()->withoutGlobalScopes()->whereKey($stale->id)->update(['created_at' => now()->subMinutes(26)]);
    $slow = ExportFile::factory()->for(Dataset::factory(), 'scope')->uploading()->create(['expires_at' => now()->addHours(10)]);
    ExportFile::query()->withoutGlobalScopes()->whereKey($slow->id)->update(['created_at' => now()->subMinutes(20)]);

    Artisan::call('exports:purge');

    expect($stale->refresh()->status)->toBe(ExportFileStatus::Failed)
        ->and($stale->error_message)->toBe('The upload did not finish.')
        ->and($slow->refresh()->status)->toBe(ExportFileStatus::Uploading);
});

it('purges an expired unfiled export like any other', function () {
    $storage = app(ExportStorage::class);
    $expired = ExportFile::factory()->for(User::factory()->create(), 'scope')->create(['expires_at' => now()->subMinute()]);
    Storage::disk('r2')->put($storage->objectKey($expired), 'x');

    Artisan::call('exports:purge');

    expect($expired->refresh()->status)->toBe(ExportFileStatus::Purged)
        ->and($storage->exists($expired))->toBeFalse();
});
