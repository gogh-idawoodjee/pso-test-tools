<?php

use App\Filament\Pages\SysFileCompare;
use App\Jobs\RunSysCompareJob;
use App\Models\User;
use App\Support\SysCompare\Storage\SysCompareStorage;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\SysFileBuilder;

beforeEach(function (): void {
    Storage::fake('sys-compare');
    $this->actingAs(User::factory()->make(['id' => 303, 'email' => 'tester@mac.com']));
});

/**
 * Stores a fake sys file the way the upload endpoint would, and returns its id.
 */
function pageUpload(string $fileName = 'prod.xml', ?SysFileBuilder $builder = null): string
{
    $xml = ($builder ?? SysFileBuilder::make()->parameter('DEFAULT', 'A', '1'))->toXml();

    return app(SysCompareStorage::class)->storeUpload(303, UploadedFile::fake()->createWithContent($fileName, $xml))->id;
}

function pageWithFiles(array $fileNames = ['prod.xml', 'tst.xml']): Testable
{
    $page = Livewire::test(SysFileCompare::class);

    foreach ($fileNames as $fileName) {
        $page->call('addUploadedFile', 'sys', pageUpload($fileName), $fileName);
    }

    return $page;
}

it('renders for a signed-in panel user', function (): void {
    Livewire::test(SysFileCompare::class)->assertOk()->assertSee('PSO Sys File Compare')->assertSee('Drop PSO system data exports here');
});

it('is in the Additional Tools navigation group', function (): void {
    expect(SysFileCompare::getNavigationGroup())->toBe('Additional Tools')
        ->and(SysFileCompare::getNavigationLabel())->toBe('PSO Sys File Compare');
});

it('adds a file with a default name from its file name and makes the first file the baseline', function (): void {
    $page = pageWithFiles(['prod.xml', 'acc.xml']);

    $environments = array_values($page->get('formData.environments'));
    $firstKey = array_key_first($page->get('formData.environments'));

    expect($environments)->toHaveCount(2)
        ->and($environments[0]['name'])->toBe('PROD')
        ->and($environments[1]['name'])->toBe('ACC')
        ->and($environments[0]['fileName'])->toBe('prod.xml')
        ->and($page->get('formData.baseline'))->toBe($firstKey);
});

it('does not trust the client: it checks the upload exists and belongs to the user', function (): void {
    $stranger = app(SysCompareStorage::class)->storeUpload(999, UploadedFile::fake()->createWithContent('theirs.xml', SysFileBuilder::make()->toXml()));

    Livewire::test(SysFileCompare::class)
        ->call('addUploadedFile', 'sys', $stranger->id, 'theirs.xml')
        ->call('addUploadedFile', 'sys', '../../.env', 'x.xml')
        ->call('addUploadedFile', 'sys', SysCompareStorage::newId(), 'missing.xml')
        ->assertSet('formData.environments', []);
});

it('cleans the file name it is given', function (): void {
    $page = Livewire::test(SysFileCompare::class)->call('addUploadedFile', 'sys', pageUpload(), "..\\..\\evil\x01name.xml");

    expect(array_values($page->get('formData.environments'))[0]['fileName'])->toBe('evilname.xml');
});

it('caps the number of files', function (): void {
    config(['sys-compare.max_files' => 2]);

    $page = pageWithFiles(['a.xml', 'b.xml']);
    $extra = pageUpload('c.xml');
    $page->call('addUploadedFile', 'sys', $extra, 'c.xml');

    expect($page->get('formData.environments'))->toHaveCount(2)
        ->and(app(SysCompareStorage::class)->uploadPath(303, $extra))->toBeNull();
});

it('blocks running with fewer than two files and explains why', function (): void {
    $page = pageWithFiles(['prod.xml']);

    expect($page->instance()->blockingReasons())->toContain('Add at least two sys files.');

    Queue::fake();
    $page->call('run');
    Queue::assertNothingPushed();
});

it('blocks running with an empty or duplicate name', function (): void {
    $page = pageWithFiles(['prod.xml', 'tst.xml']);
    $keys = array_keys($page->get('formData.environments'));

    $page->set("formData.environments.{$keys[1]}.name", '');
    expect($page->instance()->blockingReasons())->toContain('Give every file a name.');

    $page->set("formData.environments.{$keys[1]}.name", 'prod');
    expect(collect($page->instance()->blockingReasons())->first(fn (string $reason): bool => str_contains($reason, 'unique')))->not->toBeNull();

    $page->set("formData.environments.{$keys[1]}.name", 'TST');
    expect($page->instance()->blockingReasons())->toBe([]);
});

it('keeps the baseline pointing at a file that is still there', function (): void {
    $page = pageWithFiles(['prod.xml', 'tst.xml']);
    $keys = array_keys($page->get('formData.environments'));

    $page->set('formData.baseline', 'not-a-real-key');

    expect($page->instance()->blockingReasons())->toBe([]);

    $page->set('formData.baseline', $keys[1]);
    $page->call('$refresh');

    expect($page->get('formData.baseline'))->toBe($keys[1]);
});

it('dispatches the job with the files in order and the chosen baseline', function (): void {
    Queue::fake();

    $page = pageWithFiles(['prod.xml', 'tst.xml']);
    $keys = array_keys($page->get('formData.environments'));
    $page->set('formData.baseline', $keys[1]);

    $page->call('run');

    Queue::assertPushed(RunSysCompareJob::class, function (RunSysCompareJob $job) use ($page): bool {
        return $job->userId === 303
            && $job->baseline === 'TST'
            && array_column($job->files, 'name') === ['PROD', 'TST']
            && array_column($job->files, 'fileName') === ['prod.xml', 'tst.xml']
            && $job->runId === $page->get('runId')
            && $job->definitions === null;
    });

    expect($page->get('jobId'))->not->toBeNull();
});

it('runs end to end and shows the results, then clears the form', function (): void {
    $prod = SysFileBuilder::make()->parameter('DEFAULT', 'Mode', 'a')
        ->version('6.16.0', 'Upgrade from 6.15.0', '2026-05-03T21:47:53+00:00', 'ifs')
        ->version('6.16.0.41', 'Update data', '2026-05-03T21:47:57+00:00', 'ifs');
    $tst = SysFileBuilder::make()->parameter('DEFAULT', 'Mode', 'b')
        ->version('6.16.0', 'Upgrade from 6.15.0', '2025-10-17T00:31:52+00:00', 'ifs')
        ->version('6.16.0.33', 'Update data', '2025-10-17T00:31:55+00:00', 'ifs');

    $page = Livewire::test(SysFileCompare::class)
        ->call('addUploadedFile', 'sys', pageUpload('prod.xml', $prod), 'prod.xml')
        ->call('addUploadedFile', 'sys', pageUpload('tst.xml', $tst), 'tst.xml')
        ->call('run')
        ->call('checkStatus');

    $page->assertSee('NOT ALL ENVIRONMENTS ARE ON THE SAME PSO VERSION')
        ->assertSee('TST is on 6.16.0.33')
        ->assertSee('Rows that differ from PROD')
        ->assertSee('HTML report')
        ->assertSee('Excel workbook')
        ->assertSet('formData.environments', [])
        ->assertSet('jobId', null)
        ->assertNotified('Comparison complete');

    expect($page->get('summary')['bannerState'])->toBe('mismatch')
        ->and($page->get('summary')['baseline'])->toBe('PROD');
});

it('shows the failure message, then clears the form, when the job fails', function (): void {
    $page = Livewire::test(SysFileCompare::class)
        ->call('addUploadedFile', 'sys', pageUpload('prod.xml'), 'prod.xml')
        ->call('addUploadedFile', 'sys', pageUpload('bad.xml', SysFileBuilder::make()), 'bad.xml');

    // Replace one upload with a non-sys file on disk, as if the upload check had been bypassed.
    $keys = array_keys($page->get('formData.environments'));
    $badId = $page->get("formData.environments.{$keys[1]}.id");
    file_put_contents(app(SysCompareStorage::class)->uploadPath(303, $badId), '<other/>');

    $page->call('run')
        ->call('checkStatus')
        ->assertSee('bad.xml')
        ->assertSee('DsSystemData')
        ->assertSet('formData.environments', [])
        ->assertNotified('Comparison failed');
});

it('deletes the stored upload when a file is removed from the list', function (): void {
    $page = pageWithFiles(['prod.xml', 'tst.xml']);
    $keys = array_keys($page->get('formData.environments'));
    $id = $page->get("formData.environments.{$keys[0]}.id");

    $page->callAction(
        TestAction::make('delete')->schemaComponent('environments')->arguments(['item' => $keys[0]]),
    );

    expect(app(SysCompareStorage::class)->uploadPath(303, $id))->toBeNull()
        ->and($page->get('formData.environments'))->toHaveCount(1);
});

it('accepts an optional definitions file and replaces it when another is added', function (): void {
    $first = pageUpload('ParamDefinitions.csv');
    $second = pageUpload('Other.csv');

    $page = Livewire::test(SysFileCompare::class)
        ->call('addUploadedFile', 'definitions', $first, 'ParamDefinitions.csv')
        ->assertSet('formData.definitions.fileName', 'ParamDefinitions.csv')
        ->call('addUploadedFile', 'definitions', $second, 'Other.csv')
        ->assertSet('formData.definitions.fileName', 'Other.csv');

    expect(app(SysCompareStorage::class)->uploadPath(303, $first))->toBeNull();

    $page->call('removeDefinitions')->assertSet('formData.definitions', null);

    expect(app(SysCompareStorage::class)->uploadPath(303, $second))->toBeNull();
});

it('passes the definitions file to the job', function (): void {
    Queue::fake();

    $definitions = pageUpload('ParamDefinitions.csv');

    pageWithFiles()->call('addUploadedFile', 'definitions', $definitions, 'ParamDefinitions.csv')->call('run');

    Queue::assertPushed(RunSysCompareJob::class, fn (RunSysCompareJob $job): bool => $job->definitions === ['id' => $definitions, 'fileName' => 'ParamDefinitions.csv']);
});

it('refuses Livewire file uploads, which would stage customer data on the shared bucket', function (): void {
    Livewire::test(SysFileCompare::class)
        ->call('_startUpload', 'formData.environments', [['name' => 'prod.xml', 'size' => 10, 'type' => 'text/xml']], false)
        ->assertForbidden();
});

it('makes the results unreachable by tampering with the locked properties', function (): void {
    Livewire::test(SysFileCompare::class)->set('runId', SysCompareStorage::newId());
})->throws(CannotUpdateLockedPropertyException::class);

it('reports a failure while checking the status once and stops polling, instead of repeating the error', function (): void {
    $page = pageWithFiles(['prod.xml', 'tst.xml'])->call('run');
    $reads = new ArrayObject(['count' => 0]);

    // From here the web process cannot read the results the worker wrote.
    $this->app->bind(SysCompareStorage::class, fn () => new class($reads) extends SysCompareStorage
    {
        public function __construct(private readonly ArrayObject $reads) {}

        public function readRunFile(int $userId, string $runId, string $fileName): ?string
        {
            $this->reads['count']++;

            throw new ErrorException('simulated: Permission denied');
        }
    });

    $page->call('checkStatus')
        ->assertSet('jobId', null)
        ->assertSee('Something went wrong while checking the comparison')
        ->assertNotified('Comparison failed');

    // Every later poll returns early: the failing read is not attempted again, so no more toasts.
    $page->call('checkStatus')->call('checkStatus')->call('checkStatus');

    expect($reads['count'])->toBe(1);
});

it('explains when the results exist but this process may not read them', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Root can read any file, so permissions cannot be simulated.');
    }

    $page = pageWithFiles(['prod.xml', 'tst.xml'])->call('run');
    $summaryPath = app(SysCompareStorage::class)->runFile(303, $page->get('runId'), SysCompareStorage::SUMMARY_FILE);

    chmod($summaryPath, 0000);

    try {
        $page->call('checkStatus')
            ->assertSet('jobId', null)
            ->assertSee('not allowed to read the results')
            ->assertSee('different users');
    } finally {
        chmod($summaryPath, 0660);
    }
});
