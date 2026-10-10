<?php

use App\Filament\Resources\DatasetResource\Pages\EditDataset;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('saves typed settings from the dataset form', function () {
    Livewire::test(EditDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->fillForm([
            'settings.display_name' => 'SaskTel',
            'settings.coverage_km' => 50,
            'settings.time_zone' => 'America/Regina',
            'settings.exclude_resource_types' => ['LONGCYCLE', 'SHORTCYCLE'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = $this->dataset->fresh()->settings;
    expect($settings->displayName)->toBe('SaskTel')
        ->and($settings->timeZone)->toBe('America/Regina')
        ->and($settings->excludeResourceTypes)->toBe(['LONGCYCLE', 'SHORTCYCLE'])
        ->and($this->dataset->fresh()->timeZone())->toBe('America/Regina');
});

it('seeds settings from a pso-tools account file', function () {
    $path = tempnam(sys_get_temp_dir(), 'acct_');
    file_put_contents($path, json_encode(['display_name' => 'SaskTel', 'coverage_km' => 50, 'resource_type_names' => ['CONSUMER' => 'consumer']]));

    Artisan::call('datasets:seed-settings', ['dataset' => $this->dataset->id, 'file' => $path, '--time-zone' => 'America/Regina']);

    $settings = $this->dataset->fresh()->settings;
    expect($settings->displayName)->toBe('SaskTel')
        ->and($settings->resourceTypeNames)->toBe(['CONSUMER' => 'consumer'])
        ->and($settings->timeZone)->toBe('America/Regina');
    @unlink($path);
});
