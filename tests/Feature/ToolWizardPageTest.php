<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Filament\Pages\Tools\SysCompareEntry;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $this->user = User::factory()->create();
    $this->environment = Environment::factory()->for($this->user)->create(['name' => 'SaskTel PROD']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('stays out of navigation until its tool is registered', function () {
    app()->instance(ToolRegistry::class, new ToolRegistry);
    expect(LoadAuditEntry::shouldRegisterNavigation())->toBeFalse();
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    expect(LoadAuditEntry::shouldRegisterNavigation())->toBeTrue();
});

it('shows the tool question and only the user own environments and datasets', function () {
    // Someone else's environment is hidden by the Environment user scope, so name it up front.
    $otherEnvironment = Environment::factory()->create(['name' => 'Someone else PROD']);
    Dataset::factory()->for($otherEnvironment)->create(['name' => 'THEIRS']);

    Livewire::test(LoadAuditEntry::class)
        ->assertOk()
        ->assertSee(ToolId::LoadAudit->question())
        ->assertFormFieldExists('environment_id')
        ->assertFormFieldExists('dataset_id')
        ->assertSee('SaskTel PROD')
        ->assertDontSee('Someone else PROD')
        ->assertDontSee('THEIRS');
});

it('pre-fills the only environment and dataset when nothing was remembered', function () {
    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id]);
});

it('pre-fills the remembered environment and dataset', function () {
    $second = Dataset::factory()->for($this->environment)->create(['name' => 'SOUTH']);
    $this->user->rememberToolContext($this->environment->id, $second->id);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['environment_id' => $this->environment->id, 'dataset_id' => $second->id]);
});

it('clears the dataset when the environment changes', function () {
    $otherEnvironment = Environment::factory()->for($this->user)->create(['name' => 'Rogers PROD']);
    // With two environments nothing is pre-filled unless remembered.
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['dataset_id' => $this->dataset->id])
        ->fillForm(['environment_id' => $otherEnvironment->id])
        ->assertFormSet(['dataset_id' => null]);
});

it('has no dataset select for an environment-scoped tool', function () {
    FakeTool::$kinds = [[ExportKind::Sys], []];
    app(ToolRegistry::class)->register(ToolId::SysCompare, FakeTool::class);

    Livewire::test(SysCompareEntry::class)
        ->assertOk()
        ->assertFormFieldExists('environment_id')
        ->assertFormFieldDoesNotExist('dataset_id');
    FakeTool::$kinds = null;
});

it('creates an environment inline and selects it', function () {
    $page = Livewire::test(LoadAuditEntry::class)
        ->callFormComponentAction('environment_id', 'createOption', data: [
            'name' => 'New env', 'base_url' => 'https://example.test', 'account_id' => 'ACME', 'username' => 'u', 'password' => 'p', 'description' => 'x',
        ])
        ->assertHasNoFormComponentActionErrors();

    $created = Environment::query()->where('name', 'New env')->where('user_id', $this->user->id)->first();
    expect($created)->not->toBeNull()
        ->and(Crypt::decryptString($created->password))->toBe('p');
    $page->assertFormSet(['environment_id' => $created->id]);
});

it('creates a dataset inline in the chosen environment and selects it', function () {
    $page = Livewire::test(LoadAuditEntry::class)
        ->callFormComponentAction('dataset_id', 'createOption', data: ['name' => 'SOUTH', 'rota' => 'R1'])
        ->assertHasNoFormComponentActionErrors();

    $created = Dataset::query()->where('name', 'SOUTH')->first();
    expect($created?->environment_id)->toBe($this->environment->id);
    $page->assertFormSet(['dataset_id' => $created->id]);
});
