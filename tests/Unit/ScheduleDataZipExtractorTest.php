<?php

use App\Support\ScheduleDataZipExtractor;

function makeTestZip(string $dir, array $entries): string
{
    $zipPath = $dir.'/input.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    return $zipPath;
}

beforeEach(function () {
    $this->dir = storage_path('framework/testing/zip-extract-'.uniqid());
    mkdir($this->dir, 0755, true);
});

afterEach(function () {
    foreach (glob($this->dir.'/*') as $file) {
        @unlink($file);
    }
    @rmdir($this->dir);
});

it('extracts the single json entry', function () {
    $zipPath = makeTestZip($this->dir, ['dsScheduleData.json' => '{"dsScheduleData":{}}']);

    $extractedPath = ScheduleDataZipExtractor::extract($zipPath, $this->dir);

    expect($extractedPath)->toBe($this->dir.'/dsScheduleData.json');
    expect(file_get_contents($extractedPath))->toBe('{"dsScheduleData":{}}');
});

it('extracts the single xml entry', function () {
    $zipPath = makeTestZip($this->dir, ['dsScheduleData.xml' => '<dsScheduleData></dsScheduleData>']);

    $extractedPath = ScheduleDataZipExtractor::extract($zipPath, $this->dir);

    expect($extractedPath)->toBe($this->dir.'/dsScheduleData.xml');
});

it('throws when the zip has more than one entry', function () {
    $zipPath = makeTestZip($this->dir, [
        'dsScheduleData.json' => '{}',
        'readme.txt' => 'hi',
    ]);

    expect(fn () => ScheduleDataZipExtractor::extract($zipPath, $this->dir))
        ->toThrow(RuntimeException::class, 'must contain exactly one file');
});

it('throws when the single entry is not json or xml', function () {
    $zipPath = makeTestZip($this->dir, ['readme.txt' => 'hi']);

    expect(fn () => ScheduleDataZipExtractor::extract($zipPath, $this->dir))
        ->toThrow(RuntimeException::class, '.json or .xml');
});

it('throws when the zip file is corrupt', function () {
    $zipPath = $this->dir.'/corrupt.zip';
    file_put_contents($zipPath, 'not a real zip');

    expect(fn () => ScheduleDataZipExtractor::extract($zipPath, $this->dir))
        ->toThrow(RuntimeException::class, 'could not be opened');
});
