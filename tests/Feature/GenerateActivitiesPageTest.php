<?php

use App\Enums\ReferenceEntityType;
use App\Filament\Pages\Activity\GenerateActivities;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->environment = Environment::factory()->for($this->user)->create();
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('renders before any environment or dataset is chosen', function () {
    Livewire::test(GenerateActivities::class)
        ->assertOk()
        ->assertSee('Pick an environment and dataset above');
});

it('resolves the chosen dataset and offers its values keyed by pso id', function () {
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlaType, 'pso_id' => 'Appointment', 'label' => 'Appointment']);

    $component = Livewire::test(GenerateActivities::class)
        ->set('environment_data.environment_id', $this->environment->id)
        ->set('environment_data.dataset_id', 'NORTH');

    expect($component->instance()->currentDataset()?->id)->toBe($this->dataset->id);
    $component->assertOk()->assertSee('Appointment (Appointment)');
});
