<?php

use App\Support\SysCompare\Storage\SysCompareStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('sys-compare');
});

function fakeSysUpload(string $name = 'prod.xml'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, '<DsSystemData />');
}

it('stores uploads per user and only finds them for that user', function (): void {
    $storage = app(SysCompareStorage::class);
    $stored = $storage->storeUpload(1, fakeSysUpload());

    expect(SysCompareStorage::isValidId($stored->id))->toBeTrue()
        ->and($storage->uploadPath(1, $stored->id))->not->toBeNull()
        ->and($storage->uploadPath(2, $stored->id))->toBeNull()
        ->and($stored->originalName)->toBe('prod.xml');
});

it('refuses ids that could traverse the disk', function (string $id): void {
    $storage = app(SysCompareStorage::class);

    expect(SysCompareStorage::isValidId($id))->toBeFalse()
        ->and($storage->uploadPath(1, $id))->toBeNull()
        ->and($storage->runFile(1, $id, 'summary.json'))->toBeNull();

    $storage->deleteUpload(1, $id);
    $storage->deleteRun(1, $id);
})->with(['../../.env', '..', '01arz3ndektsv4rrffq69g5fav/../x', '', 'UPPERCASE0000000000000000A', str_repeat('a', 27)]);

it('deletes an upload', function (): void {
    $storage = app(SysCompareStorage::class);
    $stored = $storage->storeUpload(1, fakeSysUpload());

    $storage->deleteUpload(1, $stored->id);

    expect($storage->uploadPath(1, $stored->id))->toBeNull()
        ->and($storage->pendingUploadCount(1))->toBe(0);
});

it('purges expired results and abandoned uploads but keeps fresh ones', function (): void {
    $storage = app(SysCompareStorage::class);
    $disk = Storage::disk('sys-compare');

    $oldRun = SysCompareStorage::newId();
    $freshRun = SysCompareStorage::newId();
    $storage->putRunFile(1, $oldRun, 'summary.json', '{}');
    $storage->putRunFile(1, $freshRun, 'summary.json', '{}');

    $oldUpload = $storage->storeUpload(1, fakeSysUpload('old.xml'));
    $freshUpload = $storage->storeUpload(1, fakeSysUpload('fresh.xml'));

    touch($disk->path("runs/1/{$oldRun}/summary.json"), now()->subMinutes(61)->getTimestamp());
    touch($disk->path("uploads/1/{$oldUpload->id}.xml"), now()->subMinutes(121)->getTimestamp());

    $removed = $storage->purgeExpired();

    expect($removed)->toBe(2)
        ->and($storage->runFile(1, $oldRun, 'summary.json'))->toBeNull()
        ->and($storage->runFile(1, $freshRun, 'summary.json'))->not->toBeNull()
        ->and($storage->uploadPath(1, $oldUpload->id))->toBeNull()
        ->and($storage->uploadPath(1, $freshUpload->id))->not->toBeNull();
});

it('is scheduled to purge every ten minutes', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('sys-compare:purge')->assertSuccessful();
});

it('purges from the console command', function (): void {
    $this->artisan('sys-compare:purge')->expectsOutputToContain('Removed 0 expired item(s).')->assertSuccessful();
});

it('writes uploads and results group-readable and group-writable whatever the process umask is', function (): void {
    // Storage::fake() ignores the configured permissions, so point the tool at a disk built from the real config.
    $root = storage_path('framework/testing/sys-compare-permissions-'.bin2hex(random_bytes(4)));
    config([
        'filesystems.disks.sys-compare-real' => [...config('filesystems.disks.sys-compare'), 'root' => $root],
        'sys-compare.disk' => 'sys-compare-real',
    ]);

    $previousUmask = umask(0022);

    try {
        $storage = app(SysCompareStorage::class);
        $stored = $storage->storeUpload(1, fakeSysUpload());
        $run = SysCompareStorage::newId();
        $storage->putRunFile(1, $run, 'summary.json', '{}');
        $zipPath = $storage->runFilePathForWriting(1, $run, 'bundle.zip');
        file_put_contents($zipPath, 'zip');
        $storage->shareWithGroup($zipPath);
    } finally {
        umask($previousUmask);
    }

    $mode = static fn (string $path): int => fileperms($path) & 0777;

    expect($mode((string) $storage->uploadPath(1, $stored->id)))->toBe(0660)
        ->and($mode($root.'/uploads/1'))->toBe(0770)
        ->and($mode($root."/runs/1/{$run}"))->toBe(0770)
        ->and($mode($root."/runs/1/{$run}/summary.json"))->toBe(0660)
        ->and($mode($zipPath))->toBe(0660);

    Storage::disk('sys-compare-real')->deleteDirectory('');
    @rmdir($root);
});
