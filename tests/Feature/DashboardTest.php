<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Filament\Widgets\QuickLaunch;
use App\Filament\Widgets\RecentRuns;
use App\Filament\Widgets\ToolCatalogue;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\ToolRegistry;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'tester@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('lists registered tools with their question and the files they need, grouped by intent', function () {
    app()->instance(ToolRegistry::class, new ToolRegistry);
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);

    Livewire::test(ToolCatalogue::class)
        ->assertSee('Analysis tools')
        ->assertSee(ToolId::LoadAudit->getLabel())
        ->assertSee(ToolId::LoadAudit->question())
        ->assertSee('Needs a '.ExportKind::Load->getLabel().' export; '.ExportKind::Sys->getLabel().' optional')
        ->assertDontSee(ToolId::RegionAvailability->getLabel())
        ->assertSee('Live PSO actions')
        ->assertSee('Setup');
});

it('shows an icon on every tile and shares the tool icon with the sidebar', function () {
    app()->instance(ToolRegistry::class, new ToolRegistry);
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);

    $html = Livewire::test(ToolCatalogue::class)->html();

    expect(substr_count($html, 'data-tool-tile'))->toBeGreaterThan(0)
        ->and(substr_count($html, '<svg'))->toBe(substr_count($html, 'data-tool-tile'))
        ->and(LoadAuditEntry::getNavigationIcon())->toBe(ToolId::LoadAudit->icon());
});

it('lists only the admin pages the user can open, dropping an empty Admin section', function () {
    Livewire::test(ToolCatalogue::class)
        ->assertDontSee('Token usage')
        ->assertDontSee('Backups')
        ->assertDontSee('>Admin<', false)
        ->assertSee('Environments');

    $this->actingAs(User::factory()->create(['email' => 'idawoodjee@mac.com']));
    Livewire::test(ToolCatalogue::class)
        ->assertSee('Admin')
        ->assertSee('Token usage')
        ->assertSee('Issue token')
        ->assertSee('Health')
        ->assertSee('Backups');
});

it('hides unregistered tools and still renders with an empty registry', function () {
    app()->instance(ToolRegistry::class, new ToolRegistry);
    Livewire::test(ToolCatalogue::class)->assertOk()->assertDontSee(ToolId::LoadAudit->getLabel());
});

it('shows the current user recent runs with a link to each result, newest first', function () {
    $mine = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'queued_at' => now()->subMinute()]);
    $newer = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::RegionAvailability, 'queued_at' => now()]);
    $theirs = ToolRun::factory()->create(['tool' => ToolId::LoadAudit]);

    Livewire::test(RecentRuns::class)
        ->assertCanSeeTableRecords([$newer, $mine], inOrder: true)
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertSee('NORTH');
});

it('replaces the default dashboard', function () {
    $this->get(Dashboard::getUrl())->assertOk()->assertSee('Analysis tools')->assertSee('Recent runs');
    expect(class_exists(QuickLaunch::class))->toBeFalse();
});
