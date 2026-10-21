<?php

use App\Support\Exports\ExportStorage;
use App\Support\Settings\SettingsFileStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid()]);
});

it('stores, reads back and deletes the fixed definitions file', function () {
    $local = tempnam(sys_get_temp_dir(), 'defs');
    file_put_contents($local, "Parameter,Definition\nMode,x\n");
    $store = app(SettingsFileStore::class);
    $store->putFromLocal(SettingsFileStore::PARAM_DEFINITIONS, $local);
    expect($store->exists(SettingsFileStore::PARAM_DEFINITIONS))->toBeTrue();
    $scratch = app(ExportStorage::class)->scratchDirectoryFor((string) Str::uuid());
    expect(file_get_contents($store->readToScratch(SettingsFileStore::PARAM_DEFINITIONS, $scratch)))->toContain('Mode,x');
    $store->delete(SettingsFileStore::PARAM_DEFINITIONS);
    expect($store->exists(SettingsFileStore::PARAM_DEFINITIONS))->toBeFalse();
});

it('refuses a key outside the settings folder', function () {
    app(SettingsFileStore::class)->exists('exports/user/1/x.xml');
})->throws(InvalidArgumentException::class);
