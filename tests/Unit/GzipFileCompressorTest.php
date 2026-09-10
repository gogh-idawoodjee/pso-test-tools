<?php

use App\Support\GzipFileCompressor;

afterEach(function () {
    foreach (glob(storage_path('framework/testing/gzip-*')) as $file) {
        @unlink($file);
    }
});

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
