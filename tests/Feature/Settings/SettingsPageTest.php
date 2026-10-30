<?php

use App\Filament\Navigation\Groups;
use App\Filament\Pages\Settings;
use App\Filament\Widgets\GoogleRoutesCache;
use App\Jobs\ImportMessageTextsJob;
use App\Models\AppSetting;
use App\Models\PsoMessageText;
use App\Models\User;
use App\Support\Booking\BookingClient;
use App\Support\ScheduleData\BroadcastSettings;
use App\Support\Settings\AppSettings;
use App\Support\Settings\SettingsFileStore;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2']);
    Http::fake(['*' => Http::response('boom', 500)]);
    $this->user = User::factory()->create(['email' => 'tester@mac.com']);
    $this->actingAs($this->user);
});

function messageTextRows(): array
{
    $now = now();

    return [
        ['language_id' => 'en', 'message_id' => 1, 'text' => 'One', 'created_at' => $now, 'updated_at' => $now],
        ['language_id' => 'en', 'message_id' => 2, 'text' => 'Two', 'created_at' => $now, 'updated_at' => $now],
        ['language_id' => 'fr', 'message_id' => 1, 'text' => 'Un', 'created_at' => $now, 'updated_at' => $now],
    ];
}

function messageTextsRecord(array $override = []): array
{
    return array_replace([
        'status' => 'ready',
        'file_name' => 'MessageTexts-fr.xml',
        'uploaded_by' => null,
        'uploaded_at' => now()->toIso8601String(),
        'languages' => ['fr'],
        'rows' => 1,
        'error' => null,
    ], $override);
}

it('sits under Admin at /settings and renders every section', function () {
    expect(Settings::getNavigationGroup())->toBe(Groups::ADMIN)
        ->and(Settings::getUrl())->toEndWith('/settings');

    $this->get(Settings::getUrl())
        ->assertOk()
        ->assertSee('Message texts')
        ->assertSee('Load audit defaults')
        ->assertSee('Gateway upload broadcast defaults')
        ->assertSee('Customer map tiles')
        ->assertSee('Timeouts')
        ->assertSee('Parameter definitions')
        ->assertSee('Google Routes cache')
        ->assertSee('No message texts loaded.')
        ->assertSeeLivewire(GoogleRoutesCache::class);
});

it('starts the audit defaults form from the shipped values and saves new ones', function () {
    Livewire::test(Settings::class)
        ->assertSet('auditDefaults.noise_event_ids', '3041,1767,2959,1964,1965,24578')
        ->assertSet('auditDefaults.complete_grace_hours', 24)
        ->set('auditDefaults.noise_event_ids', ' 1, 2 ')
        ->set('auditDefaults.flag_activities', 8)
        ->call('saveAuditDefaults')
        ->assertHasNoErrors();

    expect(app(AppSettings::class)->noiseEventIds())->toBe('1,2')
        ->and(app(AppSettings::class)->flagActivities())->toBe(8);
});

it('saves a blank noise ids value as no noise ids, not the shipped list', function () {
    Livewire::test(Settings::class)
        ->set('auditDefaults.noise_event_ids', '')
        ->call('saveAuditDefaults')
        ->assertHasNoErrors()
        ->assertSet('auditDefaults.noise_event_ids', '');

    expect(AppSetting::query()->find(AppSettings::NOISE_EVENT_IDS)->value)->toBe('')
        ->and(app(AppSettings::class)->noiseEventIds())->toBe('');
});

it('starts the broadcast defaults from the shipped values and saves new ones', function () {
    Livewire::test(Settings::class)
        ->assertSet('broadcastDefaults.broadcast_id', 'WEBHOOK_COMPLETE')
        ->assertSet('broadcastDefaults.broadcast_maximum_wait', 'PT1M')
        ->assertSet('broadcastDefaults.broadcast_maximum_frequency', 'PT10M')
        ->assertSet('broadcastDefaults.broadcast_minimum_plan_quality', 85)
        ->set('broadcastDefaults.broadcast_id', ' TEAM_HOOK ')
        ->set('broadcastDefaults.broadcast_maximum_frequency', 'PT15M')
        ->set('broadcastDefaults.broadcast_required_tables', ['Plan', 'Allocation'])
        ->call('saveBroadcastDefaults')
        ->assertHasNoErrors()
        ->assertSet('broadcastDefaults.broadcast_id', 'TEAM_HOOK');

    expect(app(AppSettings::class)->gatewayBroadcastDefaults())->toBe([
        'id' => 'TEAM_HOOK',
        'plan_type' => 'CHANGE',
        'minimum_plan_quality' => 85,
        'maximum_wait' => 'PT1M',
        'maximum_frequency' => 'PT15M',
        'allocation_type' => 1,
        'required_tables' => ['Plan', 'Allocation'],
    ]);
    expect(BroadcastSettings::defaults()->maximumFrequency)->toBe('PT15M');
});

it('refuses a broadcast default that is not a duration, on the field, and keeps the saved values', function () {
    Livewire::test(Settings::class)
        ->set('broadcastDefaults.broadcast_maximum_frequency', 'ten minutes')
        ->call('saveBroadcastDefaults')
        ->assertHasErrors(['broadcastDefaults.broadcast_maximum_frequency']);

    expect(app(AppSettings::class)->gatewayBroadcastDefaults())->toBeNull();
});

it('resets the broadcast defaults to the shipped values', function () {
    app(AppSettings::class)->saveGatewayBroadcastDefaults(
        BroadcastSettings::fromArray(['id' => 'TEAM_HOOK'])->toArray(),
        null,
    );

    Livewire::test(Settings::class)
        ->assertSet('broadcastDefaults.broadcast_id', 'TEAM_HOOK')
        ->callAction('resetBroadcastDefaults')
        ->assertSet('broadcastDefaults.broadcast_id', 'WEBHOOK_COMPLETE');

    expect(app(AppSettings::class)->gatewayBroadcastDefaults())->toBeNull();
});

it('validates the audit defaults like the audit form does', function () {
    Livewire::test(Settings::class)
        ->set('auditDefaults.noise_event_ids', 'a,b')
        ->set('auditDefaults.complete_grace_hours', 169)
        ->call('saveAuditDefaults')
        ->assertHasErrors(['auditDefaults.noise_event_ids', 'auditDefaults.complete_grace_hours']);
});

it('lists every loaded language from the table and names only the last upload', function () {
    PsoMessageText::insert(messageTextRows());
    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, messageTextsRecord(['uploaded_by' => $this->user->id]));

    $this->get(Settings::getUrl())
        ->assertSee('en')
        ->assertSee('2 texts')
        ->assertSee('fr')
        ->assertSee('1 text')
        ->assertSee('MessageTexts-fr.xml')
        ->assertSee($this->user->name)
        ->assertSeeHtml('The Load audit uses <span class="font-mono">en</span>.');
});

it('stores the upload, records importing and queues the import', function () {
    Queue::fake();

    Livewire::test(Settings::class)
        ->callAction('uploadMessageTexts', ['file' => UploadedFile::fake()->createWithContent('MessageTexts-en.xml', '<DsSystemData/>')])
        ->assertHasNoActionErrors();

    $record = app(AppSettings::class)->messageTexts();
    expect($record['status'])->toBe('importing')
        ->and($record['file_name'])->toBe('MessageTexts-en.xml');
    Queue::assertPushed(ImportMessageTextsJob::class, static fn (ImportMessageTextsJob $job): bool => $job->fileName === 'MessageTexts-en.xml'
        && $job->uploadedAt === $record['uploaded_at']
        && Storage::disk('r2')->exists($job->objectKey));
});

it('refuses a file that is not xml or zip', function () {
    Queue::fake();

    Livewire::test(Settings::class)
        ->callAction('uploadMessageTexts', ['file' => UploadedFile::fake()->create('texts.csv', 10, 'text/csv')])
        ->assertHasActionErrors(['file']);

    Queue::assertNothingPushed();
});

it('shows the import error and polls while importing', function () {
    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, messageTextsRecord(['status' => 'importing', 'file_name' => 'x.xml', 'languages' => [], 'rows' => 0]));
    $this->get(Settings::getUrl())->assertSee('Importing x.xml')->assertSee('wire:poll', false);

    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, messageTextsRecord(['status' => 'failed', 'file_name' => 'x.xml', 'languages' => [], 'rows' => 0, 'error' => 'No Message_Text rows in this file.']));
    $this->get(Settings::getUrl())->assertSee('No Message_Text rows in this file.')->assertDontSee('wire:poll', false);
});

it('clears the loaded texts', function () {
    PsoMessageText::insert(messageTextRows());
    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, messageTextsRecord());

    Livewire::test(Settings::class)->callAction('clearMessageTexts');

    expect(PsoMessageText::count())->toBe(0)
        ->and(app(AppSettings::class)->messageTexts())->toBeNull();
});

it('marks the import failed and drops the stored upload when it cannot be queued', function () {
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('down'));

    Livewire::test(Settings::class)
        ->callAction('uploadMessageTexts', ['file' => UploadedFile::fake()->createWithContent('MessageTexts-en.xml', '<DsSystemData/>')])
        ->assertHasNoActionErrors();

    $record = app(AppSettings::class)->messageTexts();
    expect($record['status'])->toBe('failed')
        ->and($record['error'])->toContain('The import could not be queued')->toContain('down')
        ->and(Storage::disk('r2')->allFiles('settings/message-texts'))->toBe([]);
});

it('offers Clear after a failed first import and forgets the record', function () {
    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, messageTextsRecord(['status' => 'failed', 'languages' => [], 'rows' => 0, 'error' => 'No Message_Text rows in this file.']));

    Livewire::test(Settings::class)
        ->assertActionVisible('clearMessageTexts')
        ->assertActionEnabled('clearMessageTexts')
        ->callAction('clearMessageTexts');

    expect(app(AppSettings::class)->messageTexts())->toBeNull();
});

it('keeps Clear disabled while an import is running', function () {
    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, messageTextsRecord(['status' => 'importing', 'languages' => [], 'rows' => 0]));

    Livewire::test(Settings::class)->assertActionDisabled('clearMessageTexts');
});

it('unlocks an import that has been importing for longer than the stale limit and stops polling', function () {
    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, messageTextsRecord(['status' => 'importing', 'file_name' => 'x.xml', 'languages' => [], 'rows' => 0, 'uploaded_at' => now()->subMinutes(31)->toIso8601String()]));

    $this->get(Settings::getUrl())
        ->assertSee('This import seems to have stopped.')
        ->assertDontSee('Importing x.xml')
        ->assertDontSee('wire:poll', false);
    Livewire::test(Settings::class)
        ->assertActionVisible('clearMessageTexts')
        ->assertActionEnabled('clearMessageTexts')
        ->assertActionEnabled('uploadMessageTexts');
});

it('still polls with Clear disabled for an import started five minutes ago', function () {
    app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, messageTextsRecord(['status' => 'importing', 'file_name' => 'x.xml', 'languages' => [], 'rows' => 0, 'uploaded_at' => now()->subMinutes(5)->toIso8601String()]));

    $this->get(Settings::getUrl())
        ->assertSee('Importing x.xml')
        ->assertSee('wire:poll', false)
        ->assertDontSee('This import seems to have stopped.');
    Livewire::test(Settings::class)
        ->assertActionDisabled('clearMessageTexts')
        ->assertActionDisabled('uploadMessageTexts');
});

function storeParamDefinitions(): void
{
    Storage::disk('r2')->put(SettingsFileStore::PARAM_DEFINITIONS, "Parameter,Definition\nMode,x\n");
    app(AppSettings::class)->put(AppSettings::PARAM_DEFINITIONS, ['file_name' => 'Old.csv', 'parameter_count' => 1, 'uploaded_by' => null, 'uploaded_at' => now()->toIso8601String()]);
}

it('stores a good ParamDefinitions.csv and shows what it covers', function () {
    Livewire::test(Settings::class)->callAction('uploadParamDefinitions', ['file' => UploadedFile::fake()->createWithContent('Mine.csv', "Parameter,Definition,Basis\nMode,x,\nFoo,y,\n")])->assertHasNoActionErrors();
    expect(Storage::disk('r2')->exists(SettingsFileStore::PARAM_DEFINITIONS))->toBeTrue()
        ->and(app(AppSettings::class)->paramDefinitions()['parameter_count'])->toBe(2);
    $this->get(Settings::getUrl())->assertSee('Mine.csv')->assertSee('2 parameters');
});

it('refuses a bad csv and keeps the stored one', function () {
    storeParamDefinitions();
    Livewire::test(Settings::class)->callAction('uploadParamDefinitions', ['file' => UploadedFile::fake()->createWithContent('Bad.csv', "Wrong,Columns\n1,2\n")])->assertHasActionErrors(['file']);
    expect(Storage::disk('r2')->get(SettingsFileStore::PARAM_DEFINITIONS))->toContain('Mode,x')
        ->and(app(AppSettings::class)->paramDefinitions()['file_name'])->toBe('Old.csv');
});

it('removes the stored definitions', function () {
    storeParamDefinitions();
    Livewire::test(Settings::class)->callAction('removeParamDefinitions');
    expect(Storage::disk('r2')->exists(SettingsFileStore::PARAM_DEFINITIONS))->toBeFalse()->and(app(AppSettings::class)->paramDefinitions())->toBeNull();
    $this->get(Settings::getUrl())->assertSee('uses the built-in definitions');
});

it('says that a blank noise list means no noise ids, and names the shipped list', function () {
    Livewire::test(Settings::class)
        ->assertSee('Leave blank to treat no event id as noise.')
        ->assertSee('The shipped list is 3041, 1767, 2959, 1964, 1965, 24578.');
});

it('starts the map tiles and timeouts from the shipped values and saves new ones that the app then uses', function () {
    Livewire::test(Settings::class)
        ->assertSet('connectionDefaults.timeout_session', 20)
        ->assertSet('connectionDefaults.timeout_appointment', 60)
        ->assertSet('connectionDefaults.map_tiles_max_zoom', 19)
        ->set('connectionDefaults.timeout_appointment', 90)
        ->set('connectionDefaults.map_tiles_url', 'https://tiles.example.com/{z}/{x}/{y}.png?key=abc')
        ->set('connectionDefaults.map_tiles_attribution', 'Tiles &copy; <a href="https://tiles.example.com/">Example</a>')
        ->call('saveConnectionDefaults')
        ->assertHasNoErrors();

    $settings = app(AppSettings::class);

    expect($settings->timeout('appointment'))->toBe(90)
        ->and($settings->timeout('session'))->toBe(20)
        ->and(BookingClient::appointmentTimeout())->toBe(90)
        ->and($settings->mapTiles()['url'])->toBe('https://tiles.example.com/{z}/{x}/{y}.png?key=abc');
});

it('refuses a tile URL without its placeholders or https', function (string $url) {
    Livewire::test(Settings::class)
        ->set('connectionDefaults.map_tiles_url', $url)
        ->call('saveConnectionDefaults')
        ->assertHasErrors(['connectionDefaults.map_tiles_url']);

    expect(app(AppSettings::class)->get(AppSettings::MAP_TILES))->toBeNull();
})->with([
    'no placeholders' => ['https://tiles.example.com/tile.png'],
    'plain http' => ['http://tiles.example.com/{z}/{x}/{y}.png'],
]);

it('refuses an attribution that holds more than text and https links', function (string $attribution) {
    Livewire::test(Settings::class)
        ->set('connectionDefaults.map_tiles_attribution', $attribution)
        ->call('saveConnectionDefaults')
        ->assertHasErrors(['connectionDefaults.map_tiles_attribution']);
})->with([
    'script' => ['<script>alert(1)</script>'],
    'event handler' => ['<a href="https://x.test/" onclick="alert(1)">x</a>'],
    'javascript link' => ['<a href="javascript:alert(1)">x</a>'],
    'image' => ['<img src=x onerror=alert(1)>'],
]);

it('accepts the shipped attribution', function () {
    expect(Settings::isSafeAttribution((string) config('psott.map_tiles.attribution')))->toBeTrue()
        ->and(Settings::isSafeAttribution('Plain text only'))->toBeTrue();
});

it('refuses a timeout outside 1 to 3600 seconds', function () {
    Livewire::test(Settings::class)
        ->set('connectionDefaults.timeout_routes', 0)
        ->set('connectionDefaults.timeout_reference_values', 3601)
        ->call('saveConnectionDefaults')
        ->assertHasErrors(['connectionDefaults.timeout_routes', 'connectionDefaults.timeout_reference_values']);
});

it('resets the map tiles and timeouts to the shipped values', function () {
    app(AppSettings::class)->saveConnectionDefaults(
        ['url' => 'https://tiles.example.com/{z}/{x}/{y}.png', 'subdomains' => '', 'max_zoom' => 12, 'attribution' => 'Example'],
        ['default' => 5, 'session' => 45, 'appointment' => 60, 'routes' => 300, 'reference_values' => 600],
        null,
    );

    Livewire::test(Settings::class)
        ->assertSet('connectionDefaults.timeout_session', 45)
        ->callAction('resetConnectionDefaults')
        ->assertSet('connectionDefaults.timeout_session', 20)
        ->assertSet('connectionDefaults.map_tiles_max_zoom', 19);

    expect(app(AppSettings::class)->get(AppSettings::TIMEOUTS))->toBeNull()
        ->and(app(AppSettings::class)->get(AppSettings::MAP_TILES))->toBeNull();
});
