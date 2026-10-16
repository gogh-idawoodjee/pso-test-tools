<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Navigation\Groups;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MyFiles;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Widgets\MyExportFiles;
use App\Filament\Widgets\MyToolRuns;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2']);
    $this->user = User::factory()->create(['email' => 'tester@mac.com']);
    $this->other = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('sits under Analysis tools at my-files with a drop target for every kind', function () {
    expect(MyFiles::getNavigationGroup())->toBe(Groups::ANALYSE)
        ->and(MyFiles::getUrl())->toEndWith('/my-files');

    $this->get(MyFiles::getUrl())
        ->assertOk()
        ->assertSee('My files')
        ->assertSee('Drop a new LOAD')->assertSee('Drop a new SYS')->assertSee('Drop a new IFS event log')
        ->assertSeeLivewire(MyExportFiles::class)
        ->assertSeeLivewire(MyToolRuns::class);
});

it('lists the user unfiled exports with the purge countdown, and no dataset or other user file', function () {
    $this->travelTo(now()->startOfMinute());
    $mine = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'prod-load.xml', 'expires_at' => now()->addHours(9)]);
    $datasetFile = ExportFile::factory()->for($this->dataset, 'scope')->create(['original_filename' => 'north-load.xml']);
    $theirs = ExportFile::factory()->for($this->other, 'scope')->create(['original_filename' => 'their-load.xml']);

    Livewire::test(MyExportFiles::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$datasetFile, $theirs])
        ->assertSee('prod-load.xml')
        ->assertSee('Purges in 9 h 0 m')
        ->assertDontSee('north-load.xml');
});

it('deletes an unfiled export with its object', function () {
    $file = ExportFile::factory()->for($this->user, 'scope')->create(['content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), 'x');

    Livewire::test(MyExportFiles::class)->callTableAction('delete', $file);

    expect(ExportFile::withoutGlobalScopes()->find($file->id))->toBeNull()
        ->and(app(ExportStorage::class)->exists($file))->toBeFalse();
});

it('lists the user unfiled runs, each opening on unfiled/me, and no dataset or other user run', function () {
    $mine = ToolRun::factory()->for($this->user, 'scope')->create(['tool' => ToolId::LoadAudit]);
    $datasetRun = ToolRun::factory()->for($this->dataset, 'scope')->create();
    $theirs = ToolRun::factory()->for($this->other, 'scope')->create();

    Livewire::test(MyToolRuns::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$datasetRun, $theirs])
        ->assertTableActionHasUrl('open', ToolRunPage::urlFor($this->user, $mine), record: $mine);
});

it('keeps the My files widgets off the dashboard', function () {
    expect(Filament::getPanel('app')->getWidgets())->not->toContain(MyExportFiles::class)->not->toContain(MyToolRuns::class);

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertDontSeeLivewire(MyExportFiles::class)
        ->assertDontSeeLivewire(MyToolRuns::class);
});
