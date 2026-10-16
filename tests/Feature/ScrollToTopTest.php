<?php

use App\Filament\Pages\Dashboard;
use App\Models\User;

it('puts the back-to-top button on panel pages', function () {
    $this->actingAs(User::factory()->create(['email' => 'consultant@mac.com']));

    $this->get(Dashboard::getUrl())->assertOk()
        ->assertSee('aria-label="Back to top"', false)
        ->assertSee('window.scrollY > 600', false);
});
