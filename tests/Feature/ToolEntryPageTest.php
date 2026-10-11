<?php

use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;
use App\Tools\ToolRegistry;
use Livewire\Livewire;
use Tests\Support\FakeTool;

it('lists the user datasets and links to each Runs tab', function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $user = User::factory()->create();
    $mine = Dataset::factory()->for(Environment::factory()->for($user))->create(['name' => 'NORTH']);
    Dataset::factory()->create(['name' => 'THEIRS']);
    $this->actingAs($user);

    Livewire::test(LoadAuditEntry::class)
        ->assertOk()
        ->assertSee('NORTH')
        ->assertDontSee('THEIRS');
});

it('stays out of navigation until its tool is registered', function () {
    // The real app registers the load audit at boot, so swap in an empty registry to see the unregistered state.
    app()->instance(ToolRegistry::class, new ToolRegistry);
    expect(LoadAuditEntry::shouldRegisterNavigation())->toBeFalse();
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    expect(LoadAuditEntry::shouldRegisterNavigation())->toBeTrue();
});
