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
            'slot_usage_rule' => 'Slot_Usage_Rule_Set',
            'appointment_template' => 'Appointment_Template',
            'sla_type' => 'SLA_Type',
        ]);
});

it('lists the PSO-sourced attribute keys per type', function () {
    expect(ReferenceEntityType::TaskType->attributeKeys())->toBe(['priority', 'base_value', 'base_duration'])
        ->and(ReferenceEntityType::AppointmentTemplate->attributeKeys())->toBe(['blocking']);

    foreach (ReferenceEntityType::cases() as $type) {
        if (! in_array($type, [ReferenceEntityType::TaskType, ReferenceEntityType::AppointmentTemplate], true)) {
            expect($type->attributeKeys())->toBe([]);
        }
    }
});

it('lists the local-only attribute keys per type', function () {
    expect(ReferenceEntityType::TaskType->localAttributeKeys())->toBe(['booking_sla_type_id'])
        ->and(ReferenceEntityType::AppointmentTemplate->localAttributeKeys())->toBe(['blocking_override']);

    foreach (ReferenceEntityType::cases() as $type) {
        if (! in_array($type, [ReferenceEntityType::TaskType, ReferenceEntityType::AppointmentTemplate], true)) {
            expect($type->localAttributeKeys())->toBe([]);
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

it('keeps the reference tables out of the deletable object list', function () {
    expect(PSOObjectRegistry::all())->not->toHaveKeys(['region', 'skill', 'activity_type', 'slot_usage_rule', 'appointment_template', 'sla_type'])
        ->and(PSOObjectRegistry::forSelect())->not->toHaveKey('region');
});
