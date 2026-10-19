<?php

use App\Enums\EventType;
use App\Enums\TaskStatus;
use App\Filament\Pages\Activity\DeleteActivities;
use App\Filament\Pages\Activity\GenerateActivities;
use App\Filament\Pages\Activity\UpdateActivityStatus;
use App\Filament\Pages\Modelling\ARPRegion;
use App\Filament\Pages\Modelling\ARPResource;
use App\Filament\Pages\Resource\ResourceEvent;
use App\Filament\Pages\Resource\ResourceUnavailability;
use App\Filament\Pages\Resource\ResourceUpdateShift;
use App\Filament\Pages\Resource\ResourceUpdateUnavailability;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    ['environment' => $this->environment] = fakePsoPagePayloadSetup($this);
});

function submitAction(string $name, string $form): TestAction
{
    return TestAction::make($name)->schemaComponent(true, $form);
}

/** Sets each dotted path; a key with a `*` fills that field on the first repeater row. */
function fillRequired(Testable $component, string $statePath, array $values): Testable
{
    foreach ($values as $path => $value) {
        if (str_contains($path, '.*.')) {
            [$repeater, $field] = explode('.*.', $path, 2);
            $rowKey = array_key_first($component->get("{$statePath}.{$repeater}"));
            $path = "{$repeater}.{$rowKey}.{$field}";
        }
        $component->set("{$statePath}.{$path}", $value);
    }

    return $component;
}

/**
 * Mounts the page, checks the submit is disabled, then that it stays disabled with the
 * required fields filled but no environment, and is enabled once the environment and dataset are picked.
 */
function assertSubmitGate(object $test, string $page, string $form, string $statePath, string $action, array $required): Testable
{
    $component = Livewire::test($page)->assertActionDisabled(submitAction($action, $form));

    fillRequired($component, $statePath, $required)
        ->assertActionDisabled(submitAction($action, $form))
        ->set('environment_data.environment_id', $test->environment->id)
        ->assertActionDisabled(submitAction($action, $form))
        ->set('environment_data.dataset_id', 'NORTH')
        ->assertActionEnabled(submitAction($action, $form));

    return $component;
}

/** Every required field filled but one (blanked, so a default does not count) leaves the submit disabled. */
function assertEachRequiredFieldGates(object $test, string $page, string $form, string $statePath, string $action, array $required): void
{
    foreach (array_keys($required) as $missing) {
        $component = psoPageWithEnvironment($page, $test->environment);
        fillRequired($component, $statePath, array_merge($required, [$missing => null]))
            ->assertActionDisabled(submitAction($action, $form));
    }
}

dataset('pages', [
    'Generate Activities' => [GenerateActivities::class, 'activity_form', 'activity_data', 'create_activity', [
        'activity_type_id' => 'Install',
        'sla_type_id' => 'Appointment',
        'base_value' => 3000,
        'duration' => 60,
        'priority' => 1,
        'latitude' => 50.4,
        'longitude' => -104.6,
    ]],
    'Update Activity Status' => [UpdateActivityStatus::class, 'activity_form', 'activity_data', 'update_status', [
        'activity_id' => 'ACT-1',
        'status' => TaskStatus::ALLOCATED->value,
    ]],
    'Delete Activities' => [DeleteActivities::class, 'activity_form', 'activity_data', 'delete_activity', [
        'activities.*.activity_id' => 'ACT-1',
    ]],
    'Resource Event' => [ResourceEvent::class, 'resource_form', 'resource_data', 'generate_event', [
        'resource_id' => 'RES-1',
        'event_type' => EventType::LOGON->value,
    ]],
    'Resource Unavailability' => [ResourceUnavailability::class, 'resource_form', 'resource_data', 'generate_unavailability', [
        'resource_id' => 'RES-1',
        'category_id' => 'TRAINING',
        'base_time' => '2026-10-20 09:00:00',
        'duration' => 60,
    ]],
    'Resource Update Shift' => [ResourceUpdateShift::class, 'resource_form', 'resource_data', 'update_shift', [
        'resource_id' => 'RES-1',
        'shift_id' => 'SHIFT-1',
    ]],
    'Resource Update Unavailability' => [ResourceUpdateUnavailability::class, 'resource_form', 'resource_data', 'update_unavailability', [
        'unavailabilities.*.unavailability_id' => 'UN-1',
    ]],
    'Generate Regions' => [ARPRegion::class, 'region_form', 'region_data', 'generate_regions', [
        'regions.*.region_id' => 'REG-1',
    ]],
    'Generate Resources' => [ARPResource::class, 'resource_form', 'resource_data', 'generate_resources', [
        'resource_type_id' => 'TECH',
        'resources.*.latitude' => 50.4,
        'resources.*.longitude' => -104.6,
    ]],
]);

it('keeps the submit disabled until the environment, dataset and required fields are filled', function (string $page, string $form, string $statePath, string $action, array $required) {
    assertSubmitGate($this, $page, $form, $statePath, $action, $required);
})->with('pages');

it('keeps the submit disabled while any one required field is empty', function (string $page, string $form, string $statePath, string $action, array $required) {
    assertEachRequiredFieldGates($this, $page, $form, $statePath, $action, $required);
})->with('pages');

it('needs a resource for Committed and higher on Update Activity Status', function () {
    psoPageWithEnvironment(UpdateActivityStatus::class, $this->environment)
        ->set('activity_data.activity_id', 'ACT-1')
        ->set('activity_data.status', TaskStatus::COMMITTED->value)
        ->assertActionDisabled(submitAction('update_status', 'activity_form'))
        ->set('activity_data.resource_id', 'RES-1')
        ->assertActionEnabled(submitAction('update_status', 'activity_form'));
});

it('needs latitude and longitude for a GPS fix on Resource Event', function () {
    psoPageWithEnvironment(ResourceEvent::class, $this->environment)
        ->set('resource_data.resource_id', 'RES-1')
        ->set('resource_data.event_type', EventType::GPSFIX->value)
        ->assertActionDisabled(submitAction('generate_event', 'resource_form'))
        ->set('resource_data.latitude', 50.4)
        ->assertActionDisabled(submitAction('generate_event', 'resource_form'))
        ->set('resource_data.longitude', -104.6)
        ->assertActionEnabled(submitAction('generate_event', 'resource_form'));
});

it('needs a rota for an ARP object on Resource Update Shift', function () {
    psoPageWithEnvironment(ResourceUpdateShift::class, $this->environment)
        ->set('resource_data.resource_id', 'RES-1')
        ->set('resource_data.shift_id', 'SHIFT-1')
        ->set('resource_data.is_arp_object', true)
        ->assertActionDisabled(submitAction('update_shift', 'resource_form'))
        ->set('resource_data.rota_id', 'ROTA-1')
        ->assertActionEnabled(submitAction('update_shift', 'resource_form'));
});
