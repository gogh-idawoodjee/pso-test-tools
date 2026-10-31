<?php

use App\Enums\ReferenceEntityType;
use App\Filament\Pages\Activity\GenerateActivities;
use App\Models\DatasetReferenceValue;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;

beforeEach(function () {
    ['environment' => $this->environment, 'dataset' => $this->dataset] = fakePsoPagePayloadSetup($this);
    $this->dataset->update(['last_synced_at' => now()]);

    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::TaskType, 'pso_id' => 'INSTALL', 'label' => 'Install']);
    DatasetReferenceValue::factory()->for($this->dataset)->manual()->create(['entity_type' => ReferenceEntityType::TaskType, 'pso_id' => 'MADE_UP', 'label' => 'MADE_UP']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::SlaType, 'pso_id' => 'Appointment', 'label' => 'Appointment']);
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Skill, 'pso_id' => 'OLD_SKILL', 'label' => 'OLD_SKILL', 'last_seen_at' => now()->subDay()]);
});

function referencePage(object $test, string $activityType, array $skills = []): Testable
{
    return psoPageWithEnvironment(GenerateActivities::class, $test->environment)
        ->set('activity_data.activity_type_id', $activityType)
        ->set('activity_data.sla_type_id', 'Appointment')
        ->set('activity_data.skills', $skills)
        ->set('activity_data.latitude', 50.4)
        ->set('activity_data.longitude', -104.6);
}

it('marks typed-in and unsynced rows in the select options', function () {
    referencePage($this, 'INSTALL')
        ->assertSee('MADE_UP (MADE_UP) [typed in, not from a PSO sync]')
        ->assertSee('Install (INSTALL)')
        ->assertDontSee('Install (INSTALL) [');
});

it('lists nothing to check when every value came from the last sync', function () {
    $page = referencePage($this, 'INSTALL');

    expect($page->instance()->unverifiedReferences())->toBe([]);
    $page->assertDontSee('Some values are not from a PSO sync');
});

it('warns on the page about a typed-in type and a skill the last sync did not see', function () {
    $page = referencePage($this, 'MADE_UP', ['OLD_SKILL']);

    expect($page->instance()->unverifiedReferences())->toBe([
        'Task Type MADE_UP: typed in, not from a PSO sync',
        'Skill OLD_SKILL: not seen in the last PSO sync',
    ]);
    $page->assertSee('Some values are not from a PSO sync')
        ->assertSee('Typed in, not from a PSO sync');
});

it('asks for confirmation before sending a typed-in value', function () {
    referencePage($this, 'MADE_UP')
        ->mountAction(TestAction::make('create_activity')->schemaComponent(true, 'activity_form'))
        ->assertActionMounted(TestAction::make('create_activity')->schemaComponent(true, 'activity_form'))
        ->assertMountedActionModalSee(['Send values PSO may not have?', 'Task Type MADE_UP: typed in, not from a PSO sync']);

    Http::assertNothingSent();
});

it('sends when every value came from the last sync', function () {
    referencePage($this, 'INSTALL')
        ->callAction(TestAction::make('create_activity')->schemaComponent(true, 'activity_form'))
        ->assertHasNoErrors();

    expect(sentPsoRequest()->data()['data']['activityTypeId'])->toBe('INSTALL');
});
