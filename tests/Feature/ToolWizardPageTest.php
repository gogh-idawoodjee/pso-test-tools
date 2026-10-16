<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Filament\Pages\Tools\ScheduleReviewEntry;
use App\Filament\Pages\Tools\SysCompareEntry;
use App\Filament\Resources\DatasetResource;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\ToolRegistry;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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
        ->assertSee('new-load.zip, Purges in 11 h 0 m');
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

it('shows the run panel after starting, with open, run again and home links', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'morning-load.zip']);

    $component = Livewire::test(LoadAuditEntry::class)->call('start')->assertHasNoFormErrors();
    $run = ToolRun::sole();

    $component->assertSee('Queued')
        ->assertSee('morning-load.zip')
        ->assertSee(ToolRunPage::urlFor($this->dataset, $run), false)
        ->assertSee('Run again')
        ->assertSee('Back to home');

    $run->update(['status' => ToolRunStatus::Succeeded, 'result' => ['ok' => true], 'warnings' => ['Two activities have no location'], 'finished_at' => now()]);
    $component->call('$refresh')->assertSee('Succeeded')->assertSee('Two activities have no location');
});

it('run again returns to the form with the same choices', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)->call('start')
        ->call('runAgain')
        ->assertSet('runId', null)
        ->assertFormSet(['dataset_id' => $this->dataset->id, 'files.load' => $load->id]);
});

it('refuses a file that is no longer usable and sends the user back to choose another', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    // The event below names the same wizard and step keys the page renders.
    $component = Livewire::test(LoadAuditEntry::class)->assertFormSet(['files.load' => $load->id])
        ->assertSeeHtml('x-ref="step-form.inputs::data::wizard-step"')
        ->assertSeeHtml('form.tool-wizard');
    $load->update(['status' => ExportFileStatus::Purged]);

    $component->call('start')
        ->assertSet('runId', null)
        ->assertNotified('The run did not start')
        ->assertFormSet(['files.load' => null])
        ->assertDispatched('go-to-wizard-step', key: 'form.tool-wizard', step: 'form.inputs::data::wizard-step');
    expect(ToolRun::count())->toBe(0);
});

it('refuses an unregistered tool URL instead of crashing, and opens a registered one', function () {
    expect(app(ToolRegistry::class)->has(ToolId::ScheduleReview))->toBeFalse();
    // A user the panel admits, so the 403 is the page's own refusal.
    $this->actingAs(User::factory()->create(['email' => 'consultant@mac.com']));

    $this->get(ScheduleReviewEntry::getUrl())->assertForbidden();
    $this->get(LoadAuditEntry::getUrl())->assertOk();
});

it('shows an option field default instead of a blank', function () {
    FakeTool::$optionFields = [TextInput::make('options.finished_hours')->default('24')];

    Livewire::test(LoadAuditEntry::class)
        ->assertFormSet(['options.finished_hours' => '24', 'environment_id' => $this->environment->id, 'dataset_id' => $this->dataset->id]);
    FakeTool::$optionFields = [];
});

it('ignores a file key the tool does not take', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->set('data.files.not-a-kind', 'x')
        ->call('start')
        ->assertHasNoFormErrors();
    expect(ToolRun::sole()->inputs['files'])->toBe(['load' => $load->id]);
});

it('locks the run id and shows the result view of the run own tool', function () {
    Queue::fake();
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    $component = Livewire::test(LoadAuditEntry::class)->call('start');
    ToolRun::sole()->update(['tool' => ToolId::RegionAvailability]);

    expect($component->instance()->resultView())->toBe('filament.tool-runs.region_availability')
        ->and(method_exists(LoadAuditEntry::class, 'resetWizard'))->toBeFalse()
        ->and(fn () => $component->set('runId', ToolRun::factory()->create()->id))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('names the configured export lifetime by the upload box', function () {
    config(['exports.ttl_hours' => 6]);

    Livewire::test(LoadAuditEntry::class)->assertSee('Files are kept for 6 hours');
});

it('offers one drop target per kind the tool takes, each to its own scope', function () {
    $html = Livewire::test(LoadAuditEntry::class)
        ->assertSee('Drop a new LOAD')
        ->assertSee('Drop a new SYS')
        ->assertDontSee('Drop a new Plan')
        ->assertDontSee('Drop a new PLAN')
        ->assertDontSee('End of day')
        ->assertDontSee('Audit messages')
        ->assertDontSee('Upload a new export')
        ->html();

    expect($html)->toContain("scope_type: 'dataset', scope_id: '{$this->dataset->getKey()}', kind: this.kind")
        ->toContain("scope_type: 'environment', scope_id: '{$this->environment->getKey()}', kind: this.kind");
});

it('polls every two seconds while an upload to either scope is still being read, and disables that select', function () {
    $component = Livewire::test(LoadAuditEntry::class)->assertDontSeeHtml('wire:poll.2s="refreshInputs"');
    $file = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['status' => ExportFileStatus::Summarising]);

    $component->call('refreshInputs')
        ->assertSeeHtml('wire:poll.2s="refreshInputs"')
        ->assertDontSeeHtml('wire:poll.5s')
        ->assertSee('Reading the file you uploaded')
        ->assertDontSee('Reading the SYS export you just uploaded')
        ->assertFormFieldDisabled('files.sys')
        ->assertFormFieldEnabled('files.load');

    $file->update(['status' => ExportFileStatus::Ready]);

    $component->call('refreshInputs')
        ->assertDontSeeHtml('wire:poll.2s="refreshInputs"')
        ->assertFormFieldEnabled('files.sys')
        ->assertFormSet(['files.sys' => $file->id]);
});

it('waits for an upload that is still being read instead of starting without it', function () {
    Queue::fake();
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    $sys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create();
    $component = Livewire::test(LoadAuditEntry::class);
    ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['status' => ExportFileStatus::Summarising]);

    $component->call('start')
        ->assertNotified(Notification::make()->title('The run did not start')->body('Wait until the SYS export you uploaded has been read, then run again.')->warning())
        ->assertFormSet(['files.sys' => $sys->id]);

    expect(ToolRun::count())->toBe(0);
});

it('warns under the dataset when it has no time zone, with a link to set it', function () {
    Livewire::test(LoadAuditEntry::class)
        ->assertSee('This dataset has no time zone set, so times will show in UTC. Set it under Setup, Datasets.')
        ->assertSeeHtml(e(DatasetResource::getUrl('view', ['record' => $this->dataset, 'action' => 'edit'])));

    $this->dataset->update(['settings' => ['time_zone' => 'America/Regina']]);

    Livewire::test(LoadAuditEntry::class)->assertDontSee('This dataset has no time zone set');
});

it('notes on the run panel when the run used UTC because the dataset has no zone', function () {
    Queue::fake();
    $run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Succeeded, 'started_by_user_id' => $this->user->id, 'summary' => ['meta' => ['time_zone_default' => true]]]);
    $other = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Succeeded, 'started_by_user_id' => $this->user->id, 'summary' => null]);

    expect(view('filament.tool-runs._run-panel', ['run' => $run, 'inputFiles' => collect(), 'resultView' => 'filament.tool-runs._generic', 'isRunning' => false])->render())
        ->toContain('Times are UTC because the dataset has no time zone set')
        ->and(view('filament.tool-runs._run-panel', ['run' => $other, 'inputFiles' => collect(), 'resultView' => 'filament.tool-runs._generic', 'isRunning' => false])->render())
        ->not->toContain('Times are UTC');
});

it('does not wait for an upload that never finished', function () {
    Queue::fake();
    config(['exports.slot_minutes' => 15]);
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    ExportFile::factory()->for($this->environment, 'scope')->sys()->create();
    $stale = ExportFile::factory()->for($this->environment, 'scope')->sys()->uploading()->create();
    ExportFile::query()->whereKey($stale->id)->update(['created_at' => now()->subMinutes(26)]);

    Livewire::test(LoadAuditEntry::class)
        ->call('refreshInputs')
        ->assertDontSeeHtml('wire:poll.2s="refreshInputs"')
        ->assertFormFieldEnabled('files.sys')
        ->call('start');

    expect(ToolRun::count())->toBe(1);
});
