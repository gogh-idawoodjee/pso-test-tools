<?php

use App\Filament\Resources\EnvironmentResource\Pages\PsoLoad;

Route::get('psoload/{record}', PsoLoad::class)->name('environments.tools');
