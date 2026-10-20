<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Livewire\ExportDropZone;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
});

it('offers every kind the scope allows by default', function () {
    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset])
        ->assertSee('Drop a new LOAD')->assertSee('Drop a new Plan')->assertSee('Drop a new End of day')->assertSee('Drop a new Audit messages')
        ->assertDontSee('Drop a new SYS');
});

it('offers only the kinds it is given', function () {
    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Load], 'compact' => true])
        ->assertSee('Drop a new LOAD')
        ->assertDontSee('Drop a new Plan')->assertDontSee('End of day')->assertDontSee('Audit messages');
});

it('uploads an environment kind to the environment', function () {
    $environment = $this->dataset->environment;

    Livewire::test(ExportDropZone::class, ['scope' => $environment, 'kinds' => [ExportKind::Sys], 'compact' => true])
        ->assertSee('Drop a new SYS')
        ->assertSeeHtml("scope_type: 'environment'")
        ->assertSeeHtml("scope_id: '{$environment->getKey()}'");
});

it('refuses a kind the scope does not take', function () {
    (new ExportDropZone)->mount($this->dataset, [ExportKind::Sys]);
})->throws(InvalidArgumentException::class, 'A SYS export cannot be uploaded to a Dataset.');

it('keeps the extensions hint on a second line, or in a title when compact', function () {
    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Load]])
        ->assertSeeHtml('<span class="block text-xs text-gray-500');
    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Load], 'compact' => true])
        ->assertDontSeeHtml('<span class="block text-xs text-gray-500')
        ->assertSeeHtml('title=".xml, .json or .zip, up to');
});

it('compiles the classes used in the livewire views', function () {
    expect(file_get_contents(resource_path('css/filament/app/theme.css')))
        ->toMatch('#@source\s+[\'"](\.\./)+resources/views/livewire/#');
});

it('shows the file the server is still reading, with a moving bar, and polls', function () {
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'north_load.zip', 'status' => ExportFileStatus::Summarising]);

    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Load], 'compact' => true])
        ->assertSee('Reading north_load.zip')
        ->assertSee('This can take a minute for large files')
        ->assertSeeHtml('data-state="reading"')
        ->assertSeeHtml('animate-indeterminate')
        ->assertSeeHtml('wire:poll.2s')
        ->assertSet('serverStates', ['load' => 'reading'])
        ->assertDontSee('Drop a new LOAD');
});

it('shows why the server could not read a file until it is dismissed', function () {
    $file = ExportFile::factory()->for($this->dataset, 'scope')->failed()->create(['kind' => ExportKind::Load, 'error_message' => 'This is not a LOAD export.']);

    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Load]])
        ->assertSee('This is not a LOAD export.')
        ->assertSeeHtml('data-state="error"')
        ->assertSee('Dismiss')
        ->assertDontSeeHtml('wire:poll.2s')
        ->call('dismiss', $file->id)
        ->assertDontSee('This is not a LOAD export.')
        ->assertSee('Drop a new LOAD')
        ->assertSeeHtml('data-state="idle"');
});

it('forgets a failure older than ten minutes', function () {
    $file = ExportFile::factory()->for($this->dataset, 'scope')->failed()->create(['kind' => ExportKind::Load, 'error_message' => 'This is not a LOAD export.']);
    ExportFile::query()->whereKey($file->id)->update(['updated_at' => now()->subMinutes(11)]);

    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Load]])
        ->assertDontSee('This is not a LOAD export.')
        ->assertSee('Drop a new LOAD');
});

it('is idle once the file is ready', function () {
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'north_load.zip']);

    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Load]])
        ->assertDontSee('Reading north_load.zip')
        ->assertSee('Drop a new LOAD')
        ->assertSeeHtml('data-state="idle"')
        ->assertDontSeeHtml('wire:poll.2s');
});

it('re-reads the server state when an upload completes', function () {
    $component = Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Load]])->assertSee('Drop a new LOAD');
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'north_load.zip', 'status' => ExportFileStatus::Summarising]);

    $component->dispatch('export-uploaded')->assertSee('Reading north_load.zip');
});

it('says an upload that never finished did not finish, and stops polling for it', function () {
    config(['exports.slot_minutes' => 15]);
    $file = ExportFile::factory()->for($this->dataset, 'scope')->uploading()->create(['kind' => ExportKind::Load, 'original_filename' => 'north_load.zip']);
    ExportFile::query()->whereKey($file->id)->update(['created_at' => now()->subMinutes(26)]);

    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Load]])
        ->assertSee('north_load.zip: Upload did not finish')
        ->assertDontSee('Reading north_load.zip')
        ->assertSeeHtml('data-state="error"')
        ->assertDontSeeHtml('wire:poll.2s')
        ->call('dismiss', $file->id)
        ->assertDontSee('Upload did not finish')
        ->assertSee('Drop a new LOAD');
});

it('still shows a slow upload inside the slot and its grace as being read', function () {
    config(['exports.slot_minutes' => 15]);
    $file = ExportFile::factory()->for($this->dataset, 'scope')->uploading()->create(['kind' => ExportKind::Load, 'original_filename' => 'north_load.zip']);
    ExportFile::query()->whereKey($file->id)->update(['created_at' => now()->subMinutes(20)]);

    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Load]])
        ->assertSee('Reading north_load.zip');
});

it('offers every kind Unfiled and posts it as unfiled for me', function () {
    Livewire::test(ExportDropZone::class, ['scope' => $this->user])
        ->assertSee('Drop a new LOAD')->assertSee('Drop a new SYS')->assertSee('Drop a new IFS event log')->assertSee('Drop a new Audit messages')
        ->assertSeeHtml("scope_type: 'unfiled'")
        ->assertSeeHtml("scope_id: 'me'")
        ->assertDontSeeHtml("scope_id: '{$this->user->id}'");
});

it('shows an unfiled upload the server is still reading', function () {
    ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'prod_load.zip', 'status' => ExportFileStatus::Summarising]);

    Livewire::test(ExportDropZone::class, ['scope' => $this->user, 'kinds' => [ExportKind::Load], 'compact' => true])
        ->assertSee('Reading prod_load.zip')
        ->assertSet('serverStates', ['load' => 'reading']);
});

it('shows how far the server has read the file', function () {
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Audits, 'original_filename' => 'audit.zip', 'status' => ExportFileStatus::Summarising, 'summary' => ['progress' => 'Read 1,200 of 25,365 messages']]);

    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset, 'kinds' => [ExportKind::Audits]])
        ->assertSee('Reading audit.zip')
        ->assertSee('Read 1,200 of 25,365 messages');
});
