<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Filament\Pages\Tools\SysCompareEntry;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\ToolRegistry;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
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

it('offers the newest usable file per kind, preselected, and names the time left', function () {
    $this->travelTo(now()->startOfMinute());
    $old = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'old-load.zip', 'created_at' => now()->subHours(3), 'expires_at' => now()->addHours(9)]);
    $new = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'new-load.zip', 'expires_at' => now()->addHours(11)]);
    ExportFile::factory()->for($this->dataset, 'scope')->purged()->create(['kind' => ExportKind::Load, 'original_filename' => 'gone.zip']);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['files.load' => $new->id])
        ->assertSee('new-load.zip')
        ->assertSee('old-load.zip')
        ->assertDontSee('gone.zip')
        ->assertSee('Purges in 11 h 0 m');
});

it('blocks the run while a required export is missing and names it', function () {
    Livewire::test(LoadAuditEntry::class)
        ->call('start')
        ->assertHasFormErrors(['files.load' => 'required'])
        ->assertSee('A LOAD export is required.');
    expect(ToolRun::count())->toBe(0);
});

it('marks the required kind and shows where a sys export comes from', function () {
    Livewire::test(LoadAuditEntry::class)
        ->assertSee('LOAD export')
        ->assertSee('Required')
        ->assertSee('Optional')
        ->assertSee('from the environment');
});

it('picks up a file that finished uploading after the step opened', function () {
    $component = Livewire::test(LoadAuditEntry::class)->assertFormSet(['files.load' => null]);
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    $component->dispatch('export-uploaded')->assertFormSet(['files.load' => $load->id]);
});

it('swaps the chosen files when the dataset changes', function () {
    $south = Dataset::factory()->for($this->environment)->create(['name' => 'SOUTH']);
    $northLoad = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    $southLoad = ExportFile::factory()->for($south, 'scope')->create(['kind' => ExportKind::Load]);
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['files.load' => $northLoad->id])
        ->fillForm(['dataset_id' => $south->id])
        ->assertFormSet(['files.load' => $southLoad->id]);
});

it('leaves an optional export the user cleared empty', function () {
    $sys = ExportFile::factory()->for($this->environment, 'scope')->create(['kind' => ExportKind::Sys]);

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['files.sys' => $sys->id])
        ->set('data.files.sys', null)
        ->dispatch('export-uploaded')
        ->assertFormSet(['files.sys' => null])
        ->call('refreshInputs')
        ->assertFormSet(['files.sys' => null]);
});

it('switches to a newer file the user just uploaded', function () {
    $this->travelTo(now()->startOfMinute());
    $old = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'created_at' => now()->subHour()]);

    $component = Livewire::test(LoadAuditEntry::class)->assertFormSet(['files.load' => $old->id]);
    $new = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    $component->dispatch('export-uploaded')->assertFormSet(['files.load' => $new->id]);
});

it('has no Options step when the tool has no option fields, and one when it has', function () {
    FakeTool::$optionFields = [];
    Livewire::test(LoadAuditEntry::class)->assertDontSee('Settings for this run');

    FakeTool::$optionFields = [TextInput::make('options.ifs_release')->label('IFS Cloud release')];
    Livewire::test(LoadAuditEntry::class)->assertSee('Settings for this run')->assertFormFieldExists('options.ifs_release');
    FakeTool::$optionFields = [];
});

it('starts a run with the chosen file and options, remembers the context and shows the run', function () {
    Queue::fake();
    FakeTool::$optionFields = [TextInput::make('options.ifs_release')];
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    $component = Livewire::test(LoadAuditEntry::class)
        ->fillForm(['options' => ['ifs_release' => '25.2.7']])
        ->call('start')
        ->assertHasNoFormErrors();
    FakeTool::$optionFields = [];

    $run = ToolRun::sole();
    expect($run->tool)->toBe(ToolId::LoadAudit)
        ->and($run->inputs)->toBe(['files' => ['load' => $load->id], 'options' => ['ifs_release' => '25.2.7']])
        ->and($component->get('runId'))->toBe($run->id)
        ->and($this->user->fresh()->last_tool_context)->toBe(['environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id]);
    Queue::assertPushed(RunToolJob::class);
});

it('refuses a scope the user does not own even if the id is forced into the form', function () {
    $theirs = Dataset::factory()->for(Environment::factory())->create();
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    // The Livewire testable has no assertNotFound and does not run the exception handler, so the raw
    // ModelNotFoundException (which Laravel renders as a 404) is what escapes.
    expect(fn () => Livewire::test(LoadAuditEntry::class)->set('data.dataset_id', $theirs->id)->call('start'))
        ->toThrow(ModelNotFoundException::class);
    expect(ToolRun::count())->toBe(0);
});
