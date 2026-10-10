<?php

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Support\ReferenceValues\ReferenceValuesUpserter;
use Carbon\CarbonImmutable;

function emptyTables(array $overrides = []): array
{
    return array_replace([
        'Region' => [], 'Skill' => [], 'Activity_Type' => [],
        'Slot_Usage_Rule_Set' => [], 'Appointment_Template' => [], 'SLA_Type' => [],
    ], $overrides);
}

beforeEach(function () {
    $this->dataset = Dataset::factory()->create();
    $this->now = CarbonImmutable::parse('2026-10-10 12:00:00');
});

it('creates pso rows for ids it has not seen, with task type attributes', function () {
    $summary = app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables([
        'Region' => [['id' => 'R1', 'description' => 'Regina']],
        'Activity_Type' => [['id' => 'INSTALL', 'description' => 'Install', 'priority' => 5, 'base_value' => 1000.5, 'base_duration' => 90]],
    ]), $this->now);

    $region = DatasetReferenceValue::withoutGlobalScopes()->where('pso_id', 'R1')->first();
    $taskType = DatasetReferenceValue::withoutGlobalScopes()->where('pso_id', 'INSTALL')->first();

    expect($region->entity_type)->toBe(ReferenceEntityType::Region)
        ->and($region->label)->toBe('Regina')
        ->and($region->origin)->toBe(ReferenceValueOrigin::Pso)
        ->and($region->last_seen_at->eq($this->now))->toBeTrue()
        ->and($taskType->attribute('priority'))->toBe(5)
        ->and($taskType->attribute('base_value'))->toBe(1000.5)
        ->and($taskType->attribute('base_duration'))->toBe(90)
        ->and($summary['region'])->toBe(['created' => 1, 'updated' => 0, 'kept' => 0, 'missing' => 0])
        ->and($summary['task_type']['created'])->toBe(1);
});

it('overwrites label and attributes of an unmodified pso row', function () {
    $row = DatasetReferenceValue::factory()->for($this->dataset)->taskType(1, 500, 30)->create(['pso_id' => 'INSTALL', 'label' => 'Old']);

    $summary = app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables([
        'Activity_Type' => [['id' => 'INSTALL', 'description' => 'New', 'priority' => 7, 'base_value' => 2000, 'base_duration' => 120]],
    ]), $this->now);

    $row->refresh();
    expect($row->label)->toBe('New')
        ->and($row->attribute('priority'))->toBe(7)
        ->and($row->is_locally_modified)->toBeFalse()
        ->and($summary['task_type']['updated'])->toBe(1);
});

it('keeps the fields of a locally modified row and only bumps last_seen_at', function () {
    $row = DatasetReferenceValue::factory()->for($this->dataset)->create([
        'pso_id' => 'R1', 'label' => 'My label', 'is_locally_modified' => true, 'last_seen_at' => $this->now->subDay(),
    ]);

    $summary = app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables([
        'Region' => [['id' => 'R1', 'description' => 'PSO label']],
    ]), $this->now);

    $row->refresh();
    expect($row->label)->toBe('My label')
        ->and($row->is_locally_modified)->toBeTrue()
        ->and($row->last_seen_at->eq($this->now))->toBeTrue()
        ->and($summary['region']['kept'])->toBe(1);
});

it('leaves rows that PSO no longer returns untouched and counts them as missing', function () {
    $gone = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'GONE', 'last_seen_at' => $this->now->subDay()]);
    $manual = DatasetReferenceValue::factory()->for($this->dataset)->manual()->create(['pso_id' => 'MINE']);

    $summary = app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables(), $this->now);

    expect($gone->refresh()->last_seen_at->eq($this->now->subDay()))->toBeTrue()
        ->and($manual->refresh()->origin)->toBe(ReferenceValueOrigin::Manual)
        ->and(DatasetReferenceValue::withoutGlobalScopes()->count())->toBe(2)
        ->and($summary['region']['missing'])->toBe(1);
});

it('promotes a manual row to pso when its id appears, overwriting unless it was named on purpose', function () {
    // A manual row created with only its id takes PSO's description; one given a real label keeps it.
    $plain = DatasetReferenceValue::factory()->for($this->dataset)->manual()->create(['pso_id' => 'A', 'label' => 'A']);
    $edited = DatasetReferenceValue::factory()->for($this->dataset)->manual()->create(['pso_id' => 'B', 'label' => 'typed and edited']);
    expect($plain->is_locally_modified)->toBeFalse()->and($edited->is_locally_modified)->toBeTrue();

    app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables([
        'Region' => [['id' => 'A', 'description' => 'From PSO'], ['id' => 'B', 'description' => 'From PSO']],
    ]), $this->now);

    expect($plain->refresh()->origin)->toBe(ReferenceValueOrigin::Pso)
        ->and($plain->label)->toBe('From PSO')
        ->and($edited->refresh()->origin)->toBe(ReferenceValueOrigin::Pso)
        ->and($edited->label)->toBe('typed and edited');
});

it('does not touch rows of another dataset', function () {
    $other = DatasetReferenceValue::factory()->create(['pso_id' => 'R1', 'label' => 'Other dataset']);

    app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables(['Region' => [['id' => 'R1', 'description' => 'Mine']]]), $this->now);

    expect($other->refresh()->label)->toBe('Other dataset');
});

it('ignores tables it does not know and rows without an id', function () {
    $summary = app(ReferenceValuesUpserter::class)->apply($this->dataset, emptyTables([
        'Resources' => [['id' => 'TECH']],
        'Region' => [['description' => 'no id'], ['id' => '', 'description' => 'blank id']],
    ]), $this->now);

    expect(DatasetReferenceValue::withoutGlobalScopes()->count())->toBe(0)
        ->and($summary)->not->toHaveKey('resources');
});
