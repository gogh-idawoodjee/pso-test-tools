<?php

use App\Classes\PSOObjectRegistry;
use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;

it('maps every entity type onto its PSO wire table', function () {
    expect(collect(ReferenceEntityType::cases())->mapWithKeys(fn (ReferenceEntityType $type) => [$type->value => $type->psoEntity()])->all())
        ->toBe([
            'region' => 'Region',
            'skill' => 'Skill',
            'task_type' => 'Activity_Type',
            'slot_usage_rule' => 'Slot_Usage_Rule',
            'appointment_template' => 'Appointment_Template',
            'sla_type' => 'SLA_Type',
        ]);
});

it('only task type carries attributes', function () {
    expect(ReferenceEntityType::TaskType->attributeKeys())->toBe(['priority', 'base_value', 'base_duration']);

    foreach (ReferenceEntityType::cases() as $type) {
        if ($type !== ReferenceEntityType::TaskType) {
            expect($type->attributeKeys())->toBe([]);
        }
    }
});

it('resolves a wire table name back to the entity type', function () {
    expect(ReferenceEntityType::fromPsoEntity('SLA_Type'))->toBe(ReferenceEntityType::SlaType)
        ->and(ReferenceEntityType::fromPsoEntity('Activity'))->toBeNull();
});

it('has a registry entry for every entity type', function () {
    foreach (ReferenceEntityType::cases() as $type) {
        expect(PSOObjectRegistry::get($type->registryKey())['entity'])->toBe($type->psoEntity());
    }
});

it('labels and colours origins', function () {
    expect(ReferenceValueOrigin::Pso->getLabel())->toBe('PSO')
        ->and(ReferenceValueOrigin::Manual->getLabel())->toBe('Manual')
        ->and(ReferenceValueOrigin::Pso->getColor())->toBe('info')
        ->and(ReferenceValueOrigin::Manual->getColor())->toBe('gray');
});
