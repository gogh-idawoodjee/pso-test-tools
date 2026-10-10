<?php

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\RegionsRelationManager;
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

function regionsTab(Dataset $dataset)
{
    return Livewire::test(RegionsRelationManager::class, ['ownerRecord' => $dataset, 'pageClass' => ViewDataset::class]);
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
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('shows the row count of its type as the tab badge', function () {
    DatasetReferenceValue::factory()->for($this->dataset)->count(3)->create();
    DatasetReferenceValue::factory()->for($this->dataset)->create(['entity_type' => ReferenceEntityType::Skill]);

    expect(RegionsRelationManager::getBadge($this->dataset, ViewDataset::class))->toBe('3')
        ->and(\App\Filament\Resources\DatasetResource\RelationManagers\SkillsRelationManager::getBadge($this->dataset, ViewDataset::class))->toBe('1')
        ->and(\App\Filament\Resources\DatasetResource\RelationManagers\SlaTypesRelationManager::getBadge($this->dataset, ViewDataset::class))->toBe('0');
});
