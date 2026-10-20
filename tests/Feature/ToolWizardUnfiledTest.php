<?php

use App\Enums\ExportKind;
use App\Enums\Tool;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Filament\Pages\Tools\RegionAvailabilityEntry;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\RunInputPicker;
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

it('offers Unfiled first, then A dataset, and opens on Unfiled with the remembered environment and dataset one click away', function () {
    Livewire::test(LoadAuditEntry::class)
        ->assertFormFieldExists('where')
        ->assertFormSet(['where' => 'unfiled', 'environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id])
        ->assertSee('Where the exports live.')
        ->assertSee('Just the file. Nothing is sent to PSO and the run is listed under My files.')
        ->assertSee('The exports belong to an environment and dataset you have set up.')
        ->assertFormFieldHidden('environment_id')
        ->assertFormFieldHidden('dataset_id')
        ->assertSeeInOrder(['Just the file. Nothing is sent to PSO', 'The exports belong to an environment and dataset you have set up.'])
        ->fillForm(['where' => 'dataset'])
        ->assertFormFieldVisible('environment_id')
        ->assertFormFieldVisible('dataset_id');
});

it('hides the environment and dataset once Unfiled is chosen', function () {
    Livewire::test(LoadAuditEntry::class)
        ->fillForm(['where' => 'unfiled'])
        ->assertFormFieldHidden('environment_id')
        ->assertFormFieldHidden('dataset_id');
});

it('opens on the unfiled files, and swaps to the dataset files when A dataset is chosen', function () {
    $unfiledLoad = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);
    $datasetLoad = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['files.load' => $unfiledLoad->id])
        ->assertFormFieldHidden('environment_id')
        ->fillForm(['where' => 'dataset'])
        ->assertFormSet(['files.load' => $datasetLoad->id])
        ->assertFormFieldVisible('environment_id')
        ->assertFormFieldVisible('dataset_id')
        ->fillForm(['where' => 'unfiled'])
        ->assertFormSet(['files.load' => $unfiledLoad->id]);
});

it('opens on Unfiled whatever the last run chose, keeping the remembered environment and dataset', function () {
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['where' => 'unfiled', 'environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id]);

    $this->user->rememberToolContext($this->environment->id, $this->dataset->id, unfiled: true);
    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['where' => 'unfiled', 'environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id]);
});

it('lists only the user own unfiled files on Inputs, SYS included, and uploads there as unfiled', function () {
    $mine = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'prod-load.xml']);
    $sys = ExportFile::factory()->for($this->user, 'scope')->sys()->create(['original_filename' => 'prod-sys.xml']);
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'north-load.xml']);
    ExportFile::factory()->for(User::factory()->create(), 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'their-load.xml']);

    $html = Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['where' => 'unfiled', 'files.load' => $mine->id, 'files.sys' => $sys->id])
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

    Livewire::test(LoadAuditEntry::class)->fillForm(['where' => 'unfiled'])->call('start')->assertHasNoFormErrors();

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

it('asks for the time zone on Options for an Unfiled run, defaulting to the remembered zone', function () {
    config(['app.timezone' => 'America/Toronto']);

    Livewire::test(LoadAuditEntry::class)
        ->fillForm(['where' => 'unfiled'])
        ->assertFormFieldExists('options.time_zone')
        ->assertFormFieldVisible('options.time_zone')
        ->assertFormSet(['options.time_zone' => 'America/Toronto'])
        ->assertSee('The zone the pages show times in.')
        ->assertSee('An Unfiled run needs a time zone.');

    $this->user->rememberToolContext(null, null, unfiled: true, timeZone: 'America/Regina');
    Livewire::test(LoadAuditEntry::class)->fillForm(['where' => 'unfiled'])->assertFormSet(['options.time_zone' => 'America/Regina']);

    $this->user->rememberToolContext(null, null, unfiled: true, timeZone: 'Mars/Olympus');
    Livewire::test(LoadAuditEntry::class)->fillForm(['where' => 'unfiled'])->assertFormSet(['options.time_zone' => 'America/Toronto']);
});

it('shows the time zone field on its own Options step when the tool has no options, and hides the field for a dataset run', function () {
    FakeTool::$optionFields = [];
    Livewire::test(LoadAuditEntry::class)
        ->fillForm(['where' => 'unfiled'])
        ->assertSee('Settings for this run')
        ->assertFormFieldVisible('options.time_zone');

    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    Livewire::test(LoadAuditEntry::class)->fillForm(['where' => 'dataset'])->assertFormFieldHidden('options.time_zone');

    Livewire::test(RegionAvailabilityEntry::class)
        ->assertFormFieldDoesNotExist('options.time_zone')
        ->assertDontSee('Settings for this run');
});

it('starts an unfiled run with the chosen zone and remembers it with the last dataset', function () {
    Queue::fake();
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id, unfiled: true);
    $load = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->fillForm(['where' => 'unfiled', 'options' => ['time_zone' => 'America/Regina']])
        ->call('start')
        ->assertHasNoFormErrors();

    $run = ToolRun::sole();
    expect($run->scope_type)->toBe($this->user->getMorphClass())
        ->and((string) $run->scope_id)->toBe((string) $this->user->id)
        ->and($run->inputs)->toBe(['files' => ['load' => $load->id], 'options' => ['time_zone' => 'America/Regina']])
        ->and($this->user->fresh()->last_tool_context)->toBe([
            'environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id, 'unfiled' => true, 'time_zone' => 'America/Regina',
        ]);
});

it('refuses a time zone that is not in the list', function () {
    Queue::fake();
    ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->fillForm(['where' => 'unfiled'])
        ->set('data.options.time_zone', 'Mars/Olympus')
        ->call('start')
        ->assertHasFormErrors(['options.time_zone']);

    expect(ToolRun::count())->toBe(0);
});

it('keeps the time zone out of a dataset run', function () {
    Queue::fake();
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->fillForm(['where' => 'dataset'])
        ->set('data.options.time_zone', 'America/Regina')
        ->call('start')
        ->assertHasNoFormErrors();

    expect(ToolRun::sole()->inputs['options'])->toBe([]);
});

it('labels a named file by its name in the Inputs dropdown, and an unnamed one by its filename', function () {
    $this->travelTo(now()->startOfMinute());
    $named = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'prod-load.xml', 'name' => 'Monday morning LOAD']);
    $plain = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'tuesday-load.xml', 'created_at' => now()->subHour()]);

    $options = app(RunInputPicker::class)->optionsFor($this->user, Tool::LoadAudit)['load'];

    expect($options[$named->id])->toContain('Monday morning LOAD')->not->toContain('prod-load.xml')
        ->and($options[$plain->id])->toContain('tuesday-load.xml');
});
