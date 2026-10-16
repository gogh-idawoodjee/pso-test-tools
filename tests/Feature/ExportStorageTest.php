<?php

use App\Models\Dataset;
use App\Models\ExportFile;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid()]);
});

it('builds object keys from uuids only', function () {
    $file = ExportFile::factory()->make(['id' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'content_type' => 'zip']);
    $file->setRelation('scope', $dataset = Dataset::factory()->make(['id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7']));
    $file->scope_type = 'dataset';
    $file->scope_id = $dataset->id;

    expect(app(ExportStorage::class)->objectKey($file))
        ->toBe('exports/dataset/7c9e6679-7425-40de-944b-e07fc1f90ae7/0f8fad5b-d9cb-469f-a165-70867728950e.zip');
});

it('refuses an id that is not a uuid', function () {
    $file = ExportFile::factory()->make(['id' => '../etc/passwd']);
    $file->scope_type = 'dataset';
    $file->scope_id = 'x';

    expect(fn () => app(ExportStorage::class)->objectKey($file))->toThrow(InvalidArgumentException::class);
});

it('reports existence and size, streams to scratch, and deletes', function () {
    $file = ExportFile::factory()->create(['content_type' => 'xml']);
    $storage = app(ExportStorage::class);
    Storage::disk('r2')->put($storage->objectKey($file), '<dsScheduleData/>');

    expect($storage->exists($file))->toBeTrue()
        ->and($storage->size($file))->toBe(strlen('<dsScheduleData/>'));

    $scratch = $storage->scratchDirectoryFor($file->id);
    $path = $storage->readToScratch($file, $scratch);
    expect(file_get_contents($path))->toBe('<dsScheduleData/>')
        ->and(str_starts_with($path, $scratch))->toBeTrue();

    $storage->removeScratch($scratch);
    expect(is_dir($scratch))->toBeFalse();

    $storage->delete($file);
    expect($storage->exists($file))->toBeFalse()
        ->and($storage->size($file))->toBeNull();
});

it('issues a presigned PUT url for the object key', function () {
    $file = ExportFile::factory()->create(['content_type' => 'xml']);
    $storage = app(ExportStorage::class);
    $key = $storage->objectKey($file);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUploadUrl')
        ->once()
        ->withArgs(static fn ($givenKey, $expiry, $options): bool => $givenKey === $key && $options['ContentType'] === 'application/xml')
        ->andReturn(['url' => 'https://r2.example/'.$key, 'headers' => ['Content-Type' => 'application/xml']]);
    Storage::set('r2', $disk);

    $upload = $storage->presignedPutUrl($file);

    expect($upload['url'])->toContain($key);
});

it('builds an unfiled object key from the user id and the file uuid', function () {
    $file = ExportFile::factory()->make(['id' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'content_type' => 'xml']);
    $file->scope_type = (new User)->getMorphClass();
    $file->scope_id = '42';

    expect(app(ExportStorage::class)->objectKey($file))->toBe('exports/user/42/0f8fad5b-d9cb-469f-a165-70867728950e.xml');
});

it('takes a number as the scope id only for a user', function (string $scopeType, string $scopeId) {
    $file = ExportFile::factory()->make(['id' => '0f8fad5b-d9cb-469f-a165-70867728950e']);
    $file->scope_type = $scopeType;
    $file->scope_id = $scopeId;

    expect(fn () => app(ExportStorage::class)->objectKey($file))->toThrow(InvalidArgumentException::class);
})->with([
    'a dataset with a number' => [Dataset::class, '42'],
    'a user with a path' => [User::class, '../42'],
    'a user with zero' => [User::class, '0'],
]);
