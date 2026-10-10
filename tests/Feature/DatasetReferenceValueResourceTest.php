<?php

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Filament\Resources\RegionResource\Pages\ListRegions;
use App\Filament\Resources\SlaTypeResource\Pages\ListSlaTypes;
use App\Filament\Resources\TaskTypeResource\Pages\ListTaskTypes;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('lists only its own entity type and only the current user datasets', function () {
    $region = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'R1']);
    $skill = DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Skill, 'pso_id' => 'S1']);
    $otherUsersRegion = DatasetReferenceValue::factory()->create(['pso_id' => 'R-OTHER']);

    Livewire::test(ListRegions::class)
        ->assertCanSeeTableRecords([$region])
        ->assertCanNotSeeTableRecords([$skill, $otherUsersRegion]);
});

it('creates a manual row of its type in the chosen dataset', function () {
    Livewire::test(ListSlaTypes::class)
        ->callAction(TestAction::make('create'), data: [
            'dataset_id' => $this->dataset->id,
            'pso_id' => 'Appointment',
            'label' => 'Appointment SLA',
        ])
        ->assertHasNoActionErrors();

    $row = DatasetReferenceValue::first();
    expect($row->entity_type)->toBe(ReferenceEntityType::SlaType)
        ->and($row->origin)->toBe(ReferenceValueOrigin::Manual)
        ->and($row->last_seen_at)->toBeNull();
});

it('refuses a duplicate pso id within the same dataset and type', function () {
    DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'R1']);

    Livewire::test(ListRegions::class)
        ->callAction(TestAction::make('create'), data: ['dataset_id' => $this->dataset->id, 'pso_id' => 'R1', 'label' => 'dup'])
        ->assertHasActionErrors(['pso_id']);
});

it('stores task type attributes from the three extra fields', function () {
    Livewire::test(ListTaskTypes::class)
        ->callAction(TestAction::make('create'), data: [
            'dataset_id' => $this->dataset->id, 'pso_id' => 'INSTALL', 'label' => 'Install',
            'attributes' => ['priority' => 5, 'base_value' => 1500, 'base_duration' => 90],
        ])
        ->assertHasNoActionErrors();

    expect(DatasetReferenceValue::first()->attribute('base_duration'))->toBe(90);
});

it('marks a pso row locally modified when edited and clears it with Reset to PSO', function () {
    $row = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'R1', 'label' => 'Regina']);

    Livewire::test(ListRegions::class)
        ->callAction(TestAction::make('edit')->table($row), data: ['label' => 'Regina (west)'])
        ->assertHasNoActionErrors();
    expect($row->refresh()->is_locally_modified)->toBeTrue();

    Livewire::test(ListRegions::class)
        ->callAction(TestAction::make('resetToPso')->table($row));
    expect($row->refresh()->is_locally_modified)->toBeFalse();
});

it('shows the missing badge for a pso row the last sync did not see', function () {
    $this->dataset->update(['last_synced_at' => now()]);
    $missing = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'GONE', 'last_seen_at' => now()->subDay()]);

    Livewire::test(ListRegions::class)
        ->assertCanSeeTableRecords([$missing])
        ->assertSee('Missing from PSO');
});

it('marks a manual row edited when its label changes, so a later sync keeps the typed name', function () {
    $row = DatasetReferenceValue::factory()->for($this->dataset)->manual()->create(['pso_id' => 'R1', 'label' => 'R1']);

    Livewire::test(ListRegions::class)
        ->callAction(TestAction::make('edit')->table($row), data: ['label' => 'Regina (my name)'])
        ->assertHasNoActionErrors();

    expect($row->refresh()->is_locally_modified)->toBeTrue();
});

it('marks a manual row edited at creation when the label is not just the pso id', function () {
    Livewire::test(ListRegions::class)
        ->callAction(TestAction::make('create'), data: ['dataset_id' => $this->dataset->id, 'pso_id' => 'R1', 'label' => 'Regina'])
        ->callAction(TestAction::make('create'), data: ['dataset_id' => $this->dataset->id, 'pso_id' => 'R2', 'label' => ''])
        ->assertHasNoActionErrors();

    expect(DatasetReferenceValue::where('pso_id', 'R1')->first()->is_locally_modified)->toBeTrue()
        ->and(DatasetReferenceValue::where('pso_id', 'R2')->first()->is_locally_modified)->toBeFalse();
});
