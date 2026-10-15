<?php

use App\Enums\Tool;
use Filament\Support\Icons\Heroicon;

it('gives every tool an icon', function () {
    foreach (Tool::cases() as $tool) {
        expect($tool->icon())->toBeInstanceOf(Heroicon::class);
    }
});
