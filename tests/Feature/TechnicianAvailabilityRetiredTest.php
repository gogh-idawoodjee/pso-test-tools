<?php

use App\Filament\Pages\TechnicianAvail;
use App\Jobs\GetTechnicianShiftsJob;
use App\Models\User;
use App\Services\TechnicianAvailabilityService;

/** The old page is replaced by the region availability tool on a dataset's Runs tab (spec 2026-10-11). */
it('no longer has the Technician Availability page, tile or service', function () {
    $this->actingAs(User::factory()->create(['email' => 'ish@mac.com']));

    expect(class_exists(TechnicianAvail::class))->toBeFalse()
        ->and(class_exists(TechnicianAvailabilityService::class))->toBeFalse()
        ->and(class_exists(GetTechnicianShiftsJob::class))->toBeFalse()
        ->and(view()->exists('components.technician-gantt'))->toBeFalse()
        ->and(is_file(base_path('tests/Fixtures/sample-technician-shifts.json')))->toBeFalse();

    $this->get('/technician-avail')->assertNotFound();
});
