<?php

use App\Filament\Pages\FilterLoadFile;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['email' => 'ish@mac.com']));
});

it('shows the dangling references warning with table, key and count', function () {
    Livewire::test(FilterLoadFile::class)
        ->set('dangling', [
            ['table' => 'Activity_Status', 'key' => 'resource_id', 'count' => 3, 'examples' => ['R1', 'R2', 'R3']],
        ])
        ->assertSee('their references no longer resolve')
        ->assertSee('Activity_Status.resource_id: 3 rows point at nothing kept (for example R1, R2, R3)');
});

it('shows no warning when nothing dangles', function () {
    Livewire::test(FilterLoadFile::class)
        ->set('dangling', [])
        ->assertDontSee('their references no longer resolve');
});
