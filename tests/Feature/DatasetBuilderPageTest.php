<?php

use App\Filament\Navigation\Groups;
use App\Filament\Pages\DatasetBuilder;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'tester@mac.com']);
    $this->actingAs($this->user);
    Storage::fake('r2');
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/datasets/DC_CA'));
});

function generateOnPage(): Testable
{
    return Livewire::test(DatasetBuilder::class)
        // 11:30 in the app's time zone (America/Toronto, EDT) is 15:30Z.
        ->fillForm(['spec' => 'DC_CA', 'now' => '2026-10-30 11:30:00'])
        ->callAction(TestAction::make('generate')->schemaComponent(true, 'form'));
}

it('sits under Setup and starts on a spec and the current minute', function () {
    expect(DatasetBuilder::getNavigationGroup())->toBe(Groups::SETUP);

    $this->get(DatasetBuilder::getUrl())->assertOk()->assertSee('Dataset Builder')->assertSee('DC_US: DC demo dataset');
});

it('generates a LOAD and shows its counts and DST bands', function () {
    $page = generateOnPage();

    $page->assertSet('summary.resources', 12)
        ->assertSee('20261030-1530-stage1.xml')
        ->assertSee('Resources by region and type (12)')
        ->assertSee('Split priority: 165 of 165 Monday shifts at 0')
        ->assertSee('12:00Z')
        ->assertSee('13:00Z')
        ->assertSee('John Henry Irons (R-TOR-007): TOR + ONT_RURAL');

    expect(storage_path('app/datasets/DC_CA/20261030-1530-stage1.xml'))->toBeFile();
});

it('downloads the generated file', function () {
    generateOnPage()
        ->callAction('download')
        ->assertFileDownloaded('20261030-1530-stage1.xml');
});

it('queues the modelling data, then the LOAD, which waits for it, with the webhook skipped by default', function () {
    $environment = Environment::factory()->create([
        'user_id' => $this->user->id,
        'base_url' => 'https://example.test',
        'account_id' => 'acc-1',
        'username' => 'test-user',
        'password' => Crypt::encryptString('secret-password'),
    ]);

    generateOnPage()
        ->fillForm(['environment_id' => $environment->id], 'sendForm')
        ->callAction(TestAction::make('send')->schemaComponent(true, 'sendForm'));

    $uploads = PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->oldest('id')->get();
    [$modelling, $load] = [$uploads[0], $uploads[1]];
    $directory = storage_path('app/datasets/DC_CA');

    expect($uploads)->toHaveCount(2)
        ->and($modelling->original_filename)->toBe('20261030-1530-stage1r.arp')
        ->and($modelling->stored_path)->toEndWith('.xml')
        ->and($modelling->skip_webhook)->toBeTrue()
        ->and($load->original_filename)->toBe('20261030-1530-stage1.xml')
        ->and($load->skip_webhook)->toBeTrue()
        ->and($load->broadcast_settings)->toBeNull()
        ->and($load->file_size_bytes)->toBe(filesize("{$directory}/20261030-1530-stage1.xml"))
        ->and(Storage::disk('r2')->get($modelling->stored_path))->toBe(file_get_contents("{$directory}/20261030-1530-stage1r.arp"))
        ->and(Storage::disk('r2')->get($load->stored_path))->toBe(file_get_contents("{$directory}/20261030-1530-stage1.xml"));

    Queue::assertPushed(SendPsoScheduleDataJob::class, 1);
    Queue::assertPushed(SendPsoScheduleDataJob::class, static function (SendPsoScheduleDataJob $job) use ($modelling, $load): bool {
        $next = unserialize($job->chained[0]);

        return $job->psoGatewayUploadId === $modelling->id && $job->afterUploadId === null && $job->password === 'secret-password'
            && count($job->chained) === 1 && $next->psoGatewayUploadId === $load->id && $next->afterUploadId === $modelling->id;
    });
});

it('downloads the modelling data and lists its counts', function () {
    generateOnPage()
        ->assertSee('20261030-1530-stage1r.arp')
        ->assertSee('2 RAM_Division')
        ->assertSee('19 RAM_Shift_Template_Item')
        ->callAction('downloadModelling')
        ->assertFileDownloaded('20261030-1530-stage1r.arp');
});

it('refuses to send to an environment with no credentials', function () {
    $environment = Environment::factory()->create(['user_id' => $this->user->id, 'base_url' => '']);

    generateOnPage()
        ->fillForm(['environment_id' => $environment->id], 'sendForm')
        ->callAction(TestAction::make('send')->schemaComponent(true, 'sendForm'));

    Notification::assertNotified('Not sent');
    expect(PsoGatewayUpload::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('will not hand out a file outside the datasets folder, whatever the path says', function () {
    $page = new DatasetBuilder;
    $page->generatedPath = base_path('.env');

    expect($page->generatedFile())->toBeNull();
});

it('reports a bad seed override as an integer error, not a crash', function () {
    Livewire::test(DatasetBuilder::class)
        ->fillForm(['spec' => 'DC_CA', 'now' => '2026-10-30 15:30:00', 'seed' => 'abc'])
        ->callAction(TestAction::make('generate')->schemaComponent(true, 'form'))
        ->assertHasFormErrors(['seed']);
});
