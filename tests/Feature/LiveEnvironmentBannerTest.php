<?php

use App\Filament\Navigation\Groups;
use App\Filament\Navigation\LivePsoActionPages;
use App\Filament\Pages\GenericDelete;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Models\User;
use Filament\Resources\Pages\Page;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('lists every page in the live group and nothing from the other groups', function () {
    $pages = LivePsoActionPages::all();
    expect($pages)->toContain(GenericDelete::class)->not->toContain(LoadAuditEntry::class);
    foreach ($pages as $page) {
        if (is_subclass_of($page, Page::class)) {
            continue;
        }
        expect($page::getNavigationGroup())->toBe(Groups::LIVE);
    }
});

it('shows the live-environment banner on a live page and not on a wizard', function () {
    Livewire::test(GenericDelete::class)->assertSee('changes a live PSO environment');
    Livewire::test(LoadAuditEntry::class)->assertDontSee('changes a live PSO environment');
});
