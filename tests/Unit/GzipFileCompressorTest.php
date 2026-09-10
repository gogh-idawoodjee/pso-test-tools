<?php

use App\Support\GzipFileCompressor;

afterEach(function () {
    foreach (glob(storage_path('framework/testing/gzip-*')) as $file) {
        @unlink($file);
    }
});

/**
 * A stream whose writes always fail, standing in for a full or broken disk.
 */
class FailingWriteStreamWrapper
{
    public mixed $context = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): false
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void {}
}

it('compresses a file such that gzdecode reproduces the original bytes', function () {
    $sourcePath = storage_path('framework/testing/gzip-source.json');
    $destinationPath = storage_path('framework/testing/gzip-dest.gz');
    $original = json_encode(['dsScheduleData' => array_fill(0, 1000, ['id' => 'ABC123', 'description' => str_repeat('x', 200)])]);
    file_put_contents($sourcePath, $original);

    $compressedSize = GzipFileCompressor::compress($sourcePath, $destinationPath);

    expect($compressedSize)->toBe(filesize($destinationPath));
    expect(gzdecode(file_get_contents($destinationPath)))->toBe($original);
    expect($compressedSize)->toBeLessThan(strlen($original));
});

it('does not load the whole source file into memory', function () {
    $sourcePath = storage_path('framework/testing/gzip-source-large.json');
    $destinationPath = storage_path('framework/testing/gzip-dest-large.gz');
    // ~20MB of repetitive JSON — big enough that file_get_contents()+gzencode()
    // would show up clearly as a memory spike proportional to file size.
    file_put_contents($sourcePath, str_repeat('{"resource_id":"ABC123","status":"active"},', 500_000));

    $before = memory_get_peak_usage(true);
    GzipFileCompressor::compress($sourcePath, $destinationPath);
    $after = memory_get_peak_usage(true);

    // A single chunk (1MB) plus overhead, not the ~20MB source file.
    expect($after - $before)->toBeLessThan(5 * 1024 * 1024);
});

it('throws instead of silently producing nothing when the source cannot be opened', function () {
    GzipFileCompressor::compress(storage_path('framework/testing/gzip-does-not-exist.json'), storage_path('framework/testing/gzip-dest-unused.gz'));
})->throws(RuntimeException::class, 'could not be opened for reading');

it('throws instead of silently producing nothing when the destination cannot be opened', function () {
    $sourcePath = storage_path('framework/testing/gzip-source-ok.json');
    file_put_contents($sourcePath, '{"dsScheduleData":{}}');

    GzipFileCompressor::compress($sourcePath, storage_path('framework/testing/gzip-no-such-dir/out.gz'));
})->throws(RuntimeException::class, 'could not be opened for writing');

it('throws instead of truncating when a write fails', function () {
    if (! in_array('gzipfailwrite', stream_get_wrappers(), true)) {
        stream_wrapper_register('gzipfailwrite', FailingWriteStreamWrapper::class);
    }

    $sourcePath = storage_path('framework/testing/gzip-source-write-fail.json');
    file_put_contents($sourcePath, str_repeat('{"resource_id":"ABC123"},', 1000));

    GzipFileCompressor::compress($sourcePath, 'gzipfailwrite://out.gz');
})->throws(RuntimeException::class, 'could not be written');
