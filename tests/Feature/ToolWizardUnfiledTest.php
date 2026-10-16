<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Filament\Pages\Tools\RegionAvailabilityEntry;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    app(ToolRegistry::class)->register(ToolId::RegionAvailability, FakeTool::class);
    $this->user = User::factory()->create();
    $this->environment = Environment::factory()->for($this->user)->create(['name' => 'SaskTel PROD']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

afterEach(function () {
    FakeTool::$optionFields = [];
});

it('offers Unfiled or a dataset, defaults to Unfiled and hides the environment and dataset', function () {
    Livewire::test(LoadAuditEntry::class)
        ->assertFormFieldExists('where')
        ->assertFormSet(['where' => 'unfiled'])
        ->assertSee('Where the exports live.')
        ->assertSee('Just the file. Nothing is sent anywhere and the run is listed under My files.')
        ->assertSee('The exports belong to an environment and dataset you have set up.')
        ->assertFormFieldHidden('environment_id')
        ->assertFormFieldHidden('dataset_id');
});

it('shows the environment and dataset once A dataset is chosen, and swaps the files', function () {
    $unfiledLoad = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);
    $datasetLoad = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['files.load' => $unfiledLoad->id])
        ->fillForm(['where' => 'dataset'])
        ->assertFormFieldVisible('environment_id')
        ->assertFormFieldVisible('dataset_id')
        ->assertFormSet(['files.load' => $datasetLoad->id])
        ->fillForm(['where' => 'unfiled'])
        ->assertFormSet(['files.load' => $unfiledLoad->id]);
});

it('opens on the choice remembered from the last run', function () {
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['where' => 'dataset', 'environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id])
        ->assertFormFieldVisible('dataset_id');

    $this->user->rememberToolContext($this->environment->id, $this->dataset->id, unfiled: true);
    Livewire::test(LoadAuditEntry::class)->assertFormSet(['where' => 'unfiled']);
});

it('lists only the user own unfiled files on Inputs, SYS included, and uploads there as unfiled', function () {
    $mine = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'prod-load.xml']);
    $sys = ExportFile::factory()->for($this->user, 'scope')->sys()->create(['original_filename' => 'prod-sys.xml']);
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'north-load.xml']);
    ExportFile::factory()->for(User::factory()->create(), 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'their-load.xml']);

    $html = Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['files.load' => $mine->id, 'files.sys' => $sys->id])
        ->assertSee('prod-load.xml')
        ->assertDontSee('north-load.xml')
        ->assertDontSee('their-load.xml')
        ->assertDontSee('from the environment')
        ->html();

    expect($html)->toContain("scope_type: 'unfiled', scope_id: 'me', kind: this.kind")
        ->not->toContain("scope_type: 'dataset'");
});

it('starts an unfiled run on the user', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)->call('start')->assertHasNoFormErrors();

    $run = ToolRun::sole();
    expect($run->scope_type)->toBe($this->user->getMorphClass())
        ->and($run->inputs['files'])->toBe(['load' => $load->id]);
});

it('keeps the Where step as it was for a tool that needs a dataset', function () {
    Livewire::test(RegionAvailabilityEntry::class)
        ->assertFormFieldDoesNotExist('where')
        ->assertFormFieldVisible('environment_id')
        ->assertFormFieldVisible('dataset_id')
        ->assertSee('The environment and dataset the exports belong to.')
        ->assertDontSee('Just the file.');
});

it('never runs a tool that needs a dataset on unfiled files, even when where is forced', function () {
    Queue::fake();
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    $datasetLoad = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(RegionAvailabilityEntry::class)
        ->set('data.where', 'unfiled')
        ->call('start')
        ->assertHasNoFormErrors();

    $run = ToolRun::sole();
    expect($run->scope_type)->toBe($this->dataset->getMorphClass())
        ->and($run->inputs['files']['load'])->toBe($datasetLoad->id);
});
