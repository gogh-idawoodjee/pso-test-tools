<?php

use App\Enums\PsoGatewayUploadStatus;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;
use App\Jobs\SendPsoScheduleDataJob;
use App\Models\Environment;
use App\Models\PsoGatewayUpload;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('creates a queued upload row and dispatches the job on submit', function () {
    Storage::fake('r2');
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $environment = Environment::factory()->create([
        'user_id' => $user->id,
        'base_url' => 'https://example.test',
        'account_id' => 'acc-1',
        'username' => 'test-user',
        'password' => Crypt::encryptString('secret-password'),
    ]);

    $file = UploadedFile::fake()->create('schedule.json', 500, 'application/json');

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm(['gateway_upload_file' => $file], 'psoload')
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'psoload'));

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(1);

    $upload = PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->first();
    expect($upload->status)->toBe(PsoGatewayUploadStatus::QUEUED);
    expect($upload->pso_environment_id)->toBe($environment->id);
    expect($upload->initiated_by_user_id)->toBe($user->id);

    Queue::assertPushed(SendPsoScheduleDataJob::class, function ($job) use ($upload) {
        return $job->psoGatewayUploadId === $upload->id
            && $job->baseUrl === 'https://example.test'
            && $job->accountId === 'acc-1'
            && $job->username === 'test-user'
            && $job->password === 'secret-password';
    });
});

it('rejects submission when no file is chosen', function () {
    Storage::fake('r2');
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $environment = Environment::factory()->create(['user_id' => $user->id]);

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->callAction(TestAction::make('submit_gateway_upload')->schemaComponent(true, 'psoload'));

    expect(PsoGatewayUpload::query()->where('pso_environment_id', $environment->id)->count())->toBe(0);
    Queue::assertNotPushed(SendPsoScheduleDataJob::class);
});
