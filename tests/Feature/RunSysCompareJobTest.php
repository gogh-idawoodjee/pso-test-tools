<?php

use App\Jobs\RunSysCompareJob;
use App\Support\SysCompare\RunSummary;
use App\Support\SysCompare\Storage\SysCompareStorage;
use App\Support\SysCompare\SysCompareArtifact;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SysFileBuilder;

const JOB_FAKE_KEY = 'sk_live_FAKE_JOB_KEY_0123456789';

beforeEach(function (): void {
    Storage::fake('sys-compare');
});

/**
 * @return array{id: string, name: string, fileName: string}
 */
function jobUpload(string $name, SysFileBuilder|string $content, string $fileName): array
{
    $xml = $content instanceof SysFileBuilder ? $content->toXml() : $content;
    $stored = app(SysCompareStorage::class)->storeUpload(7, UploadedFile::fake()->createWithContent($fileName, $xml));

    return ['id' => $stored->id, 'name' => $name, 'fileName' => $fileName];
}

function jobBuilder(string $latestVersion): SysFileBuilder
{
    return SysFileBuilder::make()
        ->parameter('DEFAULT', 'RoutingApiKey', JOB_FAKE_KEY)
        ->parameter('DEFAULT', 'Mode', $latestVersion)
        ->user('u1', 'Alice Example')
        ->version('6.16.0', 'Upgrade from 6.15.0', '2026-05-03T21:47:53+00:00', 'ifs')
        ->version($latestVersion, 'Update data', '2026-05-03T21:47:57+00:00', 'ifs');
}

function runJob(array $files, string $baseline, ?array $definitions = null): string
{
    $runId = SysCompareStorage::newId();

    RunSysCompareJob::dispatchSync('job-1', 7, $runId, $files, $baseline, $definitions);

    return $runId;
}

it('writes every output and a summary, then deletes the uploads', function (): void {
    $files = [jobUpload('PROD', jobBuilder('6.16.0.41'), 'prod.xml'), jobUpload('TST', jobBuilder('6.16.0.33'), 'tst.xml')];

    $runId = runJob($files, 'PROD');
    $storage = app(SysCompareStorage::class);

    expect(Cache::get('sys-compare-job:job-1:status'))->toBe('complete')
        ->and(Cache::get('sys-compare-job:job-1:progress'))->toBe(100);

    foreach ([SysCompareArtifact::Report, SysCompareArtifact::Csv, SysCompareArtifact::Xlsx] as $artifact) {
        expect($storage->runFile(7, $runId, $artifact))->not->toBeNull("{$artifact->value} missing");
    }

    $summary = RunSummary::fromArray(json_decode((string) $storage->readRunFile(7, $runId, SysCompareStorage::SUMMARY_FILE), true));

    expect($summary->bannerState)->toBe('mismatch')
        ->and($summary->bannerDetail)->toContain('TST is on 6.16.0.33')
        ->and($summary->baseline)->toBe('PROD');

    foreach ($files as $file) {
        expect($storage->uploadPath(7, $file['id']))->toBeNull('upload was not deleted');
    }
});

it('keeps the API key and user data out of every stored output', function (): void {
    $runId = runJob([jobUpload('PROD', jobBuilder('6.16.0.41'), 'prod.xml'), jobUpload('TST', jobBuilder('6.16.0.41'), 'tst.xml')], 'PROD');
    $directory = Storage::disk('sys-compare')->path("runs/7/{$runId}");

    $contents = collect(glob($directory.'/*') ?: [])->map(static fn (string $path): string => (string) file_get_contents($path));

    foreach (glob($directory.'/*.{zip,xlsx}', GLOB_BRACE) ?: [] as $archive) {
        $zip = new ZipArchive;
        $zip->open($archive);

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $contents->push((string) $zip->getFromIndex($index));
        }

        $zip->close();
    }

    expect($contents->count())->toBeGreaterThan(5);

    foreach ($contents as $content) {
        expect($content)->not->toContain(JOB_FAKE_KEY)->not->toContain('Alice Example');
    }
});

it('applies a user-supplied definitions csv', function (): void {
    $definitions = jobUpload('defs', "Parameter,Definition,Basis\nMode,What mode means,\n", 'ParamDefinitions.csv');
    $files = [jobUpload('PROD', jobBuilder('6.16.0.41'), 'prod.xml'), jobUpload('TST', jobBuilder('6.16.0.33'), 'tst.xml')];

    $runId = runJob($files, 'PROD', ['id' => $definitions['id'], 'fileName' => 'ParamDefinitions.csv']);
    $report = (string) app(SysCompareStorage::class)->readRunFile(7, $runId, SysCompareArtifact::Report->fileName());

    expect($report)->toContain('What mode means')
        ->and(app(SysCompareStorage::class)->uploadPath(7, $definitions['id']))->toBeNull();
});

it('writes the missing definitions template only when something is undefined', function (): void {
    $files = [
        jobUpload('PROD', SysFileBuilder::make()->parameter('DEFAULT', 'TotallyUnknownParameter', '1'), 'prod.xml'),
        jobUpload('TST', SysFileBuilder::make()->parameter('DEFAULT', 'TotallyUnknownParameter', '2'), 'tst.xml'),
    ];

    $runId = runJob($files, 'PROD');

    expect(app(SysCompareStorage::class)->runFile(7, $runId, SysCompareArtifact::Template))->not->toBeNull();
});

it('fails with a plain-English message naming the bad file, keeps no outputs and still deletes the uploads', function (): void {
    $files = [
        jobUpload('PROD', jobBuilder('6.16.0.41'), 'prod.xml'),
        jobUpload('TST', '<dsScheduleData><Secret>do-not-echo</Secret></dsScheduleData>', 'wrong.xml'),
    ];

    $runId = runJob($files, 'PROD');
    $storage = app(SysCompareStorage::class);

    expect(Cache::get('sys-compare-job:job-1:status'))->toBe('failed')
        ->and(Cache::get('sys-compare-job:job-1:message'))->toContain('wrong.xml')->toContain('DsSystemData')->not->toContain('do-not-echo')
        ->and($storage->runFile(7, $runId, SysCompareArtifact::Report))->toBeNull()
        ->and($storage->uploadPath(7, $files[0]['id']))->toBeNull()
        ->and($storage->uploadPath(7, $files[1]['id']))->toBeNull();
});

it('explains when an upload has expired', function (): void {
    $files = [jobUpload('PROD', jobBuilder('6.16.0.41'), 'prod.xml'), jobUpload('TST', jobBuilder('6.16.0.41'), 'tst.xml')];
    app(SysCompareStorage::class)->deleteUpload(7, $files[1]['id']);

    runJob($files, 'PROD');

    expect(Cache::get('sys-compare-job:job-1:status'))->toBe('failed')
        ->and(Cache::get('sys-compare-job:job-1:message'))->toContain('tst.xml')->toContain('no longer available');
});

it('reports a bad definitions file by name', function (): void {
    $definitions = jobUpload('defs', "Wrong,Columns\n1,2\n", 'ParamDefinitions.csv');
    $files = [jobUpload('PROD', jobBuilder('6.16.0.41'), 'prod.xml'), jobUpload('TST', jobBuilder('6.16.0.41'), 'tst.xml')];

    runJob($files, 'PROD', ['id' => $definitions['id'], 'fileName' => 'ParamDefinitions.csv']);

    expect(Cache::get('sys-compare-job:job-1:status'))->toBe('failed')
        ->and(Cache::get('sys-compare-job:job-1:message'))->toContain('ParamDefinitions.csv')->toContain('Parameter, Definition');
});

it('fails clearly when the comparison itself is invalid', function (): void {
    $files = [jobUpload('PROD', jobBuilder('6.16.0.41'), 'prod.xml'), jobUpload('PROD', jobBuilder('6.16.0.41'), 'tst.xml')];

    runJob($files, 'PROD');

    expect(Cache::get('sys-compare-job:job-1:status'))->toBe('failed')
        ->and(Cache::get('sys-compare-job:job-1:message'))->toContain('more than once');
});
