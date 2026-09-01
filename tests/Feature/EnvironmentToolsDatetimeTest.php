<?php

use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('sends the user-picked datetime to PSO, not the current time', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Http::fake([
        '*' => Http::response(['data' => ['payloadToPso' => []]], 200),
    ]);

    $environment = Environment::factory()->create(['user_id' => $user->id]);
    Dataset::factory()->for($environment)->create(['name' => 'ds-1']);

    $chosenDatetime = now()->subDays(10)->startOfMinute();

    Livewire::test(EnvironmentTools::class, ['record' => $environment->getRouteKey()])
        ->fillForm([
            'dataset_id' => 'ds-1',
            'send_to_pso' => false,
            'datetime' => $chosenDatetime->format('Y-m-d H:i:s'),
        ], 'psoload')
        ->callAction(TestAction::make('push_it')->schemaComponent(true, 'psoload'));

    Http::assertSent(function ($request) use ($chosenDatetime) {
        $sentDatetime = data_get($request->data(), 'environment.datetime');

        return $sentDatetime !== null
            && Carbon::parse($sentDatetime)->eq($chosenDatetime);
    });
});
