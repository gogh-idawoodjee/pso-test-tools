<?php

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\CustomersRelationManager;
use App\Filament\Resources\DatasetResource\RelationManagers\ExportFilesRelationManager;
use App\Filament\Resources\DatasetResource\RelationManagers\ReferenceValuesRelationManager;
use App\Filament\Resources\DatasetResource\RelationManagers\ToolRunsRelationManager;
use App\Filament\Resources\EnvironmentResource\RelationManagers\DatasetsRelationManager;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Models\Environment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

/** The Reference values tab; its type switch starts on Regions. */
function regionsTab(Dataset $dataset)
{
    return Livewire::test(ReferenceValuesRelationManager::class, ['ownerRecord' => $dataset, 'pageClass' => ViewDataset::class]);
}

it('lets a user add a region from the dataset page', function () {
    regionsTab($this->dataset)
        ->callTableAction('create', data: ['pso_id' => 'NORTH', 'label' => 'North'])
        ->assertHasNoTableActionErrors();

    expect(DatasetReferenceValue::where('dataset_id', $this->dataset->id)->ofType(ReferenceEntityType::Region)->where('pso_id', 'NORTH')->exists())->toBeTrue();
});

it('shows a validation error, not a crash, for a duplicate pso id on the dataset page', function () {
    DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'NORTH']);

    regionsTab($this->dataset)
        ->callTableAction('create', data: ['pso_id' => 'NORTH', 'label' => 'dup'])
        ->assertHasTableActionErrors(['pso_id']);
});

it('shows a validation error for a duplicate pso id in the customer region create option', function () {
    DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'NORTH']);

    Livewire::test(CreateCustomer::class)
        ->fillForm(['dataset_id' => $this->dataset->id])
        ->callAction(TestAction::make('createOption')->schemaComponent('region_id'), data: ['pso_id' => 'NORTH', 'label' => 'dup'])
        ->assertHasActionErrors(['pso_id']);
});

it('refuses to create a manual row in another user dataset', function () {
    $foreign = Dataset::factory()->create();

    expect(fn () => DatasetReferenceValue::createManual($foreign->id, ReferenceEntityType::Region, ['pso_id' => 'X']))
        ->toThrow(ModelNotFoundException::class);
});

it('shows the row count of every type as the tab badge', function () {
    DatasetReferenceValue::factory()->for($this->dataset)->count(3)->create();
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Skill]);

    expect(ReferenceValuesRelationManager::getBadge($this->dataset, ViewDataset::class))->toBe('4');
});

it('lists one type at a time, regions first, and follows the type switch', function () {
    $region = DatasetReferenceValue::factory()->for($this->dataset)->create(['pso_id' => 'R1', 'label' => 'Region one']);
    $task = DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::TaskType, 'pso_id' => 'T1', 'label' => 'Task one', 'attributes' => ['priority' => 7]]);

    regionsTab($this->dataset)
        ->assertCanSeeTableRecords([$region])
        ->assertCanNotSeeTableRecords([$task])
        ->assertTableColumnHidden('attributes.priority')
        ->filterTable(ReferenceValuesRelationManager::FILTER, ReferenceEntityType::TaskType->value)
        ->assertCanSeeTableRecords([$task])
        ->assertCanNotSeeTableRecords([$region])
        ->assertTableColumnVisible('attributes.priority');
});

it('creates a row of the selected type', function () {
    regionsTab($this->dataset)
        ->filterTable(ReferenceValuesRelationManager::FILTER, ReferenceEntityType::Skill->value)
        ->callTableAction('create', data: ['pso_id' => 'FIBRE', 'label' => 'Fibre'])
        ->assertHasNoTableActionErrors();

    expect(DatasetReferenceValue::where('dataset_id', $this->dataset->id)->ofType(ReferenceEntityType::Skill)->where('pso_id', 'FIBRE')->exists())->toBeTrue()
        ->and(DatasetReferenceValue::where('dataset_id', $this->dataset->id)->ofType(ReferenceEntityType::Region)->exists())->toBeFalse();
});

it('shows one icon on every tab of the dataset and environment pages', function () {
    foreach ([
        ReferenceValuesRelationManager::class,
        CustomersRelationManager::class,
        ExportFilesRelationManager::class,
        ToolRunsRelationManager::class,
        DatasetsRelationManager::class,
        App\Filament\Resources\EnvironmentResource\RelationManagers\ExportFilesRelationManager::class,
        App\Filament\Resources\EnvironmentResource\RelationManagers\ToolRunsRelationManager::class,
    ] as $manager) {
        expect($manager::getIcon($this->dataset, ViewDataset::class))->not->toBeNull($manager);
    }
});
