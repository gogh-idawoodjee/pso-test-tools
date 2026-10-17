<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\ToolRunPage;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->export = ExportFile::factory()->for($this->dataset, 'scope')->create([
        'kind' => ExportKind::Load, 'original_filename' => 'north-load.trimmed.xml', 'status' => ExportFileStatus::Summarising,
    ]);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::TrimExport,
        'started_by_user_id' => $this->user->id,
        'result' => [
            'tables' => ['Input_Reference' => ['before' => 1, 'kept' => 1], 'Plan_Travel' => ['before' => 4812, 'kept' => 0], 'Activity' => ['before' => 7620, 'kept' => 8]],
            'removed_tables' => ['Plan_Travel'],
            'dangling' => [['table' => 'Activity_Status', 'field' => 'resource_id', 'count' => 76, 'examples' => ['R9', 'R10', 'R11']]],
            'warnings' => ['Some kept rows point at rows that are not in the file. The list below names them.'],
            'count_only' => false, 'format' => 'xml', 'zipped' => false, 'export_file_id' => $this->export->id,
        ],
        'summary' => ['line' => 'Removed 1 table and 12,424 rows. Kept 2 tables and 9 rows.', 'tables_removed' => 1, 'rows_kept' => 9, 'rows_removed' => 12424, 'count_only' => false],
        'warnings' => ['Some kept rows point at rows that are not in the file. The list below names them.'],
    ]);
    $this->actingAs($this->user);
    $this->params = ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id];
});

it('shows the counts, the dangling list, the new export and its download', function () {
    Livewire::test(ToolRunPage::class, $this->params)
        ->assertOk()
        ->assertSee('Removed 1 table and 12,424 rows. Kept 2 tables and 9 rows.')
        ->assertSee('Table removed')
        ->assertSee('7,620')
        ->assertSee('References left pointing at nothing')
        ->assertSee('R9, R10, R11')
        ->assertSee('already broken in the original export')
        ->assertSee('north-load.trimmed.xml')
        ->assertSee('It is being read; other tools can use it once it is ready.')
        ->assertSee('Download the trimmed export')
        ->assertSeeHtml(e(route('exports.download', ['exportFile' => $this->export->id])))
        ->assertSee('Open the dataset')
        ->assertSee('Some kept rows point at rows that are not in the file.');
});

it('says Count only and offers no download when nothing was written', function () {
    $this->run->update([
        'result' => [...$this->run->result, 'count_only' => true, 'export_file_id' => null],
        'summary' => ['line' => 'Would remove 1 table and 12,424 rows, and keep 2 tables and 9 rows. Nothing was written (Count only).'],
    ]);

    Livewire::test(ToolRunPage::class, $this->params)
        ->assertSee('Count only: no file was written.')
        ->assertDontSee('Download the trimmed export')
        ->assertSee('References left pointing at nothing');
});

it('says so when the trimmed export has been deleted', function () {
    $this->export->delete();

    Livewire::test(ToolRunPage::class, $this->params)
        ->assertSee('The trimmed export has been deleted.')
        ->assertDontSee('Download the trimmed export');
});

it('links an Unfiled run to My files', function () {
    $export = ExportFile::factory()->for($this->user, 'scope')->create(['original_filename' => 'unfiled.trimmed.xml']);
    $run = ToolRun::factory()->for($this->user, 'scope')->create([
        'tool' => ToolId::TrimExport,
        'started_by_user_id' => $this->user->id,
        'result' => [...$this->run->result, 'export_file_id' => $export->id],
        'summary' => $this->run->summary,
    ]);

    Livewire::test(ToolRunPage::class, ['scope' => 'unfiled', 'scopeId' => 'me', 'run' => $run->id])
        ->assertSee('in My files')
        ->assertSee('Open My files')
        ->assertDontSee('Open the dataset')
        ->assertSee('Download the trimmed export');
});

it('says when the export has expired and when no references are broken', function () {
    $this->export->update(['status' => ExportFileStatus::Ready, 'expires_at' => now()->subMinute()]);
    $this->run->update(['result' => [...$this->run->result, 'dangling' => []]]);

    Livewire::test(ToolRunPage::class, $this->params)
        ->assertSee('This export has expired, so it cannot be downloaded.')
        ->assertDontSee('Download the trimmed export')
        ->assertSee('No broken references were found.');
});

it('never shows the run to another user', function () {
    $this->actingAs(User::factory()->create(['email' => 'other@mac.com']));

    Livewire::test(ToolRunPage::class, $this->params)->assertNotFound();
});
