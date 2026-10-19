<?php

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Filament\Resources\AppointmentTemplateResource\Pages\ListAppointmentTemplates;
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

it('sets a task type booking SLA type without marking the row edited', function () {
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlaType, 'pso_id' => 'Appointment', 'label' => 'Appointment SLA']);
    $row = DatasetReferenceValue::factory()->for($this->dataset)->taskType(5, 1500, 90)->create(['pso_id' => 'INSTALL', 'label' => 'Install']);

    Livewire::test(ListTaskTypes::class)
        ->callAction(TestAction::make('edit')->table($row), data: ['attributes' => ['booking_sla_type_id' => 'Appointment']])
        ->assertHasNoActionErrors();

    $row->refresh();
    expect($row->attribute('booking_sla_type_id'))->toBe('Appointment')
        ->and($row->attribute('priority'))->toEqual(5)
        ->and($row->is_locally_modified)->toBeFalse();
});

it('saves a template blocking edit as an override without freezing the row, and Reset to PSO clears it', function () {
    $row = DatasetReferenceValue::factory()->for($this->dataset)->create([
        'entity_type' => ReferenceEntityType::AppointmentTemplate, 'pso_id' => 'STANDARD', 'label' => 'Std', 'attributes' => ['blocking' => true],
    ]);

    // An untouched form stores no override.
    Livewire::test(ListAppointmentTemplates::class)
        ->callAction(TestAction::make('edit')->table($row), data: [])
        ->assertHasNoActionErrors();
    expect($row->refresh()->attribute('blocking_override'))->toBeNull()
        ->and($row->attribute('blocking'))->toBeTrue()
        ->and($row->is_locally_modified)->toBeFalse();

    Livewire::test(ListAppointmentTemplates::class)
        ->callAction(TestAction::make('edit')->table($row), data: ['blocking_override' => false])
        ->assertHasNoActionErrors();
    expect($row->refresh()->attribute('blocking_override'))->toBeFalse()
        ->and($row->attribute('blocking'))->toBeTrue()
        ->and($row->blockingDefault())->toBeFalse()
        ->and($row->is_locally_modified)->toBeFalse();

    Livewire::test(ListAppointmentTemplates::class)
        ->assertTableColumnStateSet('blocking', 'No (manual)', $row)
        ->callAction(TestAction::make('resetToPso')->table($row));
    expect($row->refresh()->attribute('blocking_override'))->toBeNull()
        ->and($row->blockingDefault())->toBeTrue();
});

it('stores an explicit blocking true on a PSO-null template as an override', function () {
    $row = DatasetReferenceValue::factory()->for($this->dataset)->create([
        'entity_type' => ReferenceEntityType::AppointmentTemplate, 'pso_id' => 'STANDARD', 'label' => 'Std', 'attributes' => ['blocking' => null],
    ]);

    Livewire::test(ListAppointmentTemplates::class)
        ->callAction(TestAction::make('edit')->table($row), data: ['blocking_override' => true])
        ->assertHasNoActionErrors();

    expect($row->refresh()->attribute('blocking_override'))->toBeTrue();
});

it('creates a manual template that blocks by default', function () {
    Livewire::test(ListAppointmentTemplates::class)
        ->callAction(TestAction::make('create'), data: ['dataset_id' => $this->dataset->id, 'pso_id' => 'STANDARD'])
        ->assertHasNoActionErrors();

    expect(DatasetReferenceValue::first()->blockingDefault())->toBeTrue();
});
