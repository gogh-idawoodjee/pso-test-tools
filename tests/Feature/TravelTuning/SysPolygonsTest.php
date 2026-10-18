<?php

use App\Enums\ExportKind;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Support\Exports\RunInputPicker;
use App\Tools\TravelTuning\SysPolygons;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-tuning-'.uniqid()]);
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create();
    $this->dataset = Dataset::factory()->for($this->environment)->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    File::deleteDirectory((string) config('exports.scratch_path'));
});

it('uses the stored count without reading the file', function () {
    $file = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['summary' => ['pso_version' => '6.14.0.0', 'polygon_count' => 7]]);

    expect(app(SysPolygons::class)->count($file))->toBe(7);
});

it('scans a zipped SYS export once, remembers the count and leaves no copy behind', function () {
    $file = storedSys($this->environment, polygonSysXml(2), zipped: true, attributes: ['summary' => ['pso_version' => '6.14.0.0']]);

    expect(app(SysPolygons::class)->count($file))->toBe(2)
        ->and($file->fresh()->summary)->toBe(['pso_version' => '6.14.0.0', 'polygon_count' => 2])
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('counts zero for a SYS export without polygons', function () {
    $file = storedSys($this->environment, sysXml());

    expect(app(SysPolygons::class)->count($file))->toBe(0);
});

it('copies a zipped SYS export into a folder as plain XML under the given name', function () {
    $file = storedSys($this->environment, polygonSysXml(1), zipped: true);
    $directory = sys_get_temp_dir().'/tuning-copy-'.uniqid();
    mkdir($directory);

    try {
        $path = app(SysPolygons::class)->localXml($file, $directory, 'polygons-2');

        expect($path)->toBe($directory.'/polygons-2.xml')
            ->and(SysPolygons::countIn($path))->toBe(1)
            ->and(array_map('basename', glob($directory.'/*')))->toBe(['polygons-2.xml']);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('refuses a file that is not a SYS export', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    expect(fn () => app(SysPolygons::class)->localXml($load, sys_get_temp_dir(), 'x'))->toThrow(RuntimeException::class, 'Only a SYS export can hold polygons.');
});

it('says in one plain sentence that a SYS export is not XML', function () {
    $file = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['content_type' => 'json', 'original_filename' => 'PSO_Sys.json']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), '{"not":"xml"}');
    $directory = sys_get_temp_dir().'/tuning-copy-'.uniqid();
    mkdir($directory);

    try {
        expect(fn () => app(SysPolygons::class)->localXml($file, $directory, 'x'))
            ->toThrow(RuntimeException::class, 'This SYS export is not XML, so its polygons cannot be read.')
            ->and(glob($directory.'/*'))->toBe([]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('lists the SYS exports of a dataset\'s environment, or of the user for Unfiled', function () {
    $environmentSys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['original_filename' => 'env_sys.xml']);
    $unfiledSys = ExportFile::factory()->for($this->user, 'scope')->sys()->create(['original_filename' => 'my_sys.xml']);
    ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['expires_at' => now()->subHour()]);

    $picker = app(RunInputPicker::class);

    expect(array_keys($picker->optionsForKind($this->dataset, ExportKind::Sys)))->toBe([$environmentSys->id])
        ->and(array_keys($picker->optionsForKind($this->user, ExportKind::Sys)))->toBe([$unfiledSys->id])
        ->and($picker->optionsForKind($this->dataset, ExportKind::Sys)[$environmentSys->id])->toContain('env_sys.xml')->toContain('Purges in');
});
