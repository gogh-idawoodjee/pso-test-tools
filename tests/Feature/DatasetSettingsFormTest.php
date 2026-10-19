<?php

use App\Filament\Resources\DatasetResource\Pages\EditDataset;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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

it('falls back to UTC when the dataset has no time zone, and says whether it has one', function () {
    config(['app.timezone' => 'America/Toronto']);

    expect($this->dataset->timeZone())->toBe('UTC')
        ->and($this->dataset->hasTimeZone())->toBeFalse()
        ->and($this->dataset->ifsTimeZone())->toBe('UTC');

    $this->dataset->update(['settings' => ['time_zone' => 'America/Regina']]);

    expect($this->dataset->fresh()->timeZone())->toBe('America/Regina')
        ->and($this->dataset->fresh()->hasTimeZone())->toBeTrue();
});

it('explains the default booking SLA type as the fallback for task types without one', function () {
    Livewire::test(EditDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->assertSee('Used when the task type has none.');
});

it('shows the settings on the dataset page and edits them from there', function () {
    $this->dataset->update(['settings' => ['display_name' => 'SaskTel', 'resource_type_names' => ['CONSUMER' => 'consumer']]]);

    Livewire::test(ViewDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->assertSee('SaskTel')
        ->assertSee('CONSUMER: consumer')
        ->assertSee('Same as time zone')
        ->assertDontSee('Windows time zone')
        ->callAction(TestAction::make('edit'), [
            'settings.time_zone' => 'America/Regina',
            'settings.supervisor_label' => 'Team lead',
        ])
        ->assertHasNoActionErrors();

    $settings = $this->dataset->fresh()->settings;
    expect($settings->timeZone)->toBe('America/Regina')
        ->and($settings->supervisorLabel)->toBe('Team lead')
        ->and($settings->displayName)->toBe('SaskTel');
});

it('no longer offers the settings nothing reads', function () {
    Livewire::test(EditDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->assertDontSee('Windows time zone')
        ->assertDontSee('Coverage radius')
        ->assertDontSee('End of day cut-off')
        ->assertSee('Names and time zones')
        ->assertSee('Region availability');
});
