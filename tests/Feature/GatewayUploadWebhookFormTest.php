<?php

use App\Enums\BroadcastAllocationType;
use App\Enums\BroadcastPlanType;
use App\Enums\GatewayUploadLogLevel;
use App\Enums\PsoGatewayUploadStatus;
use App\Filament\Resources\EnvironmentResource\Pages\EditEnvironment;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\PsoGatewayUploadLogLine;
use App\Models\User;
use App\Support\PsoServices\PsoServicesSessionClient;
use App\Support\PsoServices\PsoServicesSessionException;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\PsoServicesFake;

function webhookFormEnvironment(): Environment
{
    $user = User::factory()->create();
    test()->actingAs($user);

    return Environment::factory()->create([
        'user_id' => $user->id,
        'base_url' => 'https://example.test',
        'account_id' => 'acc-1',
        'username' => 'test-user',
        'password' => Crypt::encryptString('secret-password'),
    ]);
}

function submitWebhookForm(Environment $environment, array $formState = []): Testable
{
    return Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm([
            'gateway_upload_file' => UploadedFile::fake()->create('schedule.json', 100, 'application/json'),
            ...$formState,
        ], 'gatewayUploadForm')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'gatewayUploadForm'));
}

beforeEach(function () {
    Storage::fake('r2');
    Queue::fake();
});

it('defaults the broadcast settings to the script values', function () {
    $environment = webhookFormEnvironment();

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->assertSchemaStateSet([
            'broadcast_id' => 'WEBHOOK_COMPLETE',
            'broadcast_plan_type' => BroadcastPlanType::COMPLETE,
            'broadcast_minimum_plan_quality' => 85,
            'broadcast_maximum_wait' => 'PT5M',
            'broadcast_allocation_type' => BroadcastAllocationType::DYNAMIC_SCHEDULING,
            'skip_webhook' => false,
            'broadcast_required_tables' => ['Allocation', 'Plan', 'Plan_Deletion', 'Appointment_Offer', 'Appointment_Summary', 'Suggested_Dispatch', 'Schedule_Exception', 'Schedule_Exception_Data'],
            'dataset_id_override' => null,
        ], 'gatewayUploadForm');
});

it('stores the override, toggle and broadcast settings on the upload row', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, [
        'dataset_id_override' => ' SASK_UAT ',
        'broadcast_id' => 'MY_HOOK',
        'broadcast_maximum_wait' => 'PT10M',
        'broadcast_minimum_plan_quality' => 90,
    ]);

    $upload = PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->firstOrFail();

    expect($upload->dataset_id_override)->toBe('SASK_UAT');
    expect($upload->skip_webhook)->toBeFalse();
    expect($upload->broadcast_settings)->toMatchArray([
        'id' => 'MY_HOOK',
        'maximum_wait' => 'PT10M',
        'minimum_plan_quality' => 90,
        'plan_type' => 'COMPLETE',
        'allocation_type' => 1,
    ]);

    Queue::assertPushed(SendPsoScheduleDataJob::class);
});

it('stores the skip toggle and ignores broadcast settings when skipped', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['skip_webhook' => true, 'broadcast_maximum_wait' => 'not a duration']);

    $upload = PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->firstOrFail();

    expect($upload->skip_webhook)->toBeTrue();
    expect($upload->broadcast_settings)->toBeNull();
});

it('refuses an invalid maximum wait and creates no upload', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['broadcast_maximum_wait' => '5 minutes']);

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a minimum plan quality outside 0 to 100', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['broadcast_minimum_plan_quality' => 150]);

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
});

it('refuses an empty required-tables list', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['broadcast_required_tables' => []]);

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
});

it('shows the run log, response and webhook link of a viewed upload', function () {
    $environment = webhookFormEnvironment();

    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'status' => PsoGatewayUploadStatus::SUCCEEDED,
        'internal_id' => '1857054',
        'response_status' => 200,
        'response_body' => '{"InternalId":"1857054"}',
        'webhook_url' => 'https://webhook.example.test/pso-env',
        'webhook_view_url' => 'https://webhook.example.test/#!/uuid-1',
        'wire_bytes' => 2097152,
        'post_duration_ms' => 4000,
        'completed_at' => now(),
    ]);

    PsoGatewayUploadLogLine::query()->create([
        'pso_gateway_upload_id' => $upload->id,
        'level' => GatewayUploadLogLevel::STEP,
        'text' => 'Ensuring webhook alias',
        'created_at' => now(),
    ]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->call('viewGatewayUpload', $upload->id)
        ->assertSee('Ensuring webhook alias')
        ->assertSee('1857054')
        ->assertSee('https://webhook.example.test/pso-env')
        ->assertSee('https://webhook.example.test/#!/uuid-1')
        ->assertSee('{"InternalId":"1857054"}');
});

it('appends only new log lines on each poll', function () {
    $environment = webhookFormEnvironment();
    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create();

    $line = static fn (string $text) => PsoGatewayUploadLogLine::query()->create([
        'pso_gateway_upload_id' => $upload->id,
        'level' => GatewayUploadLogLevel::SUB,
        'text' => $text,
        'created_at' => now(),
    ]);

    $line('first');

    $component = Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->call('viewGatewayUpload', $upload->id);

    expect($component->get('gatewayLogLines'))->toHaveCount(1);

    $line('second');
    $component->call('pollGatewayUploadLog');
    $component->call('pollGatewayUploadLog');

    expect(collect($component->get('gatewayLogLines'))->pluck('text')->all())->toBe(['first', 'second']);
});

it('will not show another environment\'s upload', function () {
    $environment = webhookFormEnvironment();
    $foreign = PsoGatewayUpload::factory()->create();

    PsoGatewayUploadLogLine::query()->create([
        'pso_gateway_upload_id' => $foreign->id,
        'level' => GatewayUploadLogLevel::SUB,
        'text' => 'foreign secret line',
        'created_at' => now(),
    ]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->call('viewGatewayUpload', $foreign->id)
        ->assertDontSee('foreign secret line');
});

it('refuses a dataset ID override with characters XML cannot carry', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['dataset_id_override' => "SASK\x01UAT"]);

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a broadcast ID with characters XML cannot carry', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['broadcast_id' => "HO\x0BOK"]);

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
});

it('refuses a required table that is not a plain identifier', function () {
    $environment = webhookFormEnvironment();

    submitWebhookForm($environment, ['broadcast_required_tables' => ['Allocation', 'Plan, Plan_Deletion']]);

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
});

it('keeps the environment record out of the gateway upload form state', function () {
    $environment = webhookFormEnvironment();

    $state = Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->get('gateway_upload_data');

    expect($state)->not->toHaveKey('password');
    expect($state)->not->toHaveKey('username');
});

it('polls a run in progress and stops polling a run that finished long ago', function () {
    $environment = webhookFormEnvironment();

    $running = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'status' => PsoGatewayUploadStatus::UPLOADING,
    ]);
    $finished = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'status' => PsoGatewayUploadStatus::SUCCEEDED,
        'completed_at' => now()->subHour(),
    ]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->call('viewGatewayUpload', $running->id)
        ->assertSeeHtml('wire:poll.2s="pollGatewayUploadLog"')
        ->call('viewGatewayUpload', $finished->id)
        ->assertDontSeeHtml('wire:poll.2s="pollGatewayUploadLog"');
});

it('does not let the client rewrite the last seen log line id', function () {
    $environment = webhookFormEnvironment();

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->set('gatewayLogLastId', 99);
})->throws(CannotUpdateLockedPropertyException::class);

it('shows the reason from pso-services when the environment credentials test fails', function () {
    $environment = webhookFormEnvironment();

    $client = Mockery::mock(PsoServicesSessionClient::class);
    $client->shouldReceive('logon')->andThrow(new PsoServicesSessionException('Base URL looks like a production gateway.', 422));
    app()->instance(PsoServicesSessionClient::class, $client);

    Livewire::test(EditEnvironment::class, ['record' => $environment->getRouteKey()])
        ->callAction('test_configuration')
        ->assertNotified(
            Notification::make()
                ->title('Something Looks Off')
                ->body('Check Configuration: Base URL looks like a production gateway.')
                ->danger(),
        );
});

it('shows the dataset id in the file apart from the one sent, and the sent one in the history', function () {
    $environment = webhookFormEnvironment();

    $upload = PsoGatewayUpload::factory()->for($environment, 'environment')->create([
        'status' => PsoGatewayUploadStatus::SUCCEEDED,
        'internal_id' => '1857054',
        'dataset_id_override' => 'NEWDS',
        'dataset_id_in_file' => 'OLDDS',
        'dataset_id' => 'NEWDS',
        'completed_at' => now(),
    ]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->call('viewGatewayUpload', $upload->id)
        ->assertSeeInOrder(['Dataset in the file', 'OLDDS', 'Dataset ID sent', 'NEWDS'])
        ->assertSeeInOrder(['Recent uploads', $upload->original_filename, '(NEWDS)']);
});

it('logs off after fetching system usage', function () {
    $environment = webhookFormEnvironment();

    Http::fake([
        ...PsoServicesFake::session(),
        '*' => Http::response(['data' => ['ScheduleDataUsages' => []]], 200),
    ]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->callAction(TestAction::make('fetch_system_usage')->schemaComponent(true, 'systemUsageForm'));

    Http::assertSent(static fn ($request) => $request->url() === PsoServicesFake::sessionUrl() && $request->method() === 'POST');
    Http::assertSent(static fn ($request) => $request->url() === PsoServicesFake::sessionUrl()
        && $request->method() === 'DELETE');
});

it('logs off after a successful configuration test', function () {
    $environment = webhookFormEnvironment();

    $client = Mockery::mock(PsoServicesSessionClient::class);
    $client->shouldReceive('logon')->once()->andReturn('tok-abc');
    $client->shouldReceive('logoff')->once()->with('https://example.test', 'tok-abc');
    app()->instance(PsoServicesSessionClient::class, $client);

    Livewire::test(EditEnvironment::class, ['record' => $environment->getRouteKey()])
        ->callAction('test_configuration')
        ->assertNotified(Notification::make()->title('Looks Good')->body('Configuration valid')->success());
});

it('still reports a valid configuration when the logoff fails', function () {
    $environment = webhookFormEnvironment();

    $client = Mockery::mock(PsoServicesSessionClient::class);
    $client->shouldReceive('logon')->once()->andReturn('tok-abc');
    $client->shouldReceive('logoff')->once()->andThrow(new PsoServicesSessionException('Internal server error.', 500));
    app()->instance(PsoServicesSessionClient::class, $client);

    Livewire::test(EditEnvironment::class, ['record' => $environment->getRouteKey()])
        ->callAction('test_configuration')
        ->assertNotified(Notification::make()->title('Looks Good')->body('Configuration valid')->success());
});
