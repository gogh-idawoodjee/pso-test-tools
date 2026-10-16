<?php

use App\Enums\Tool;

it('lets only the file-only tools run Unfiled', function () {
    $accepting = array_values(array_filter(Tool::cases(), static fn (Tool $tool): bool => $tool->acceptsUnfiled()));

    expect($accepting)->toBe([Tool::LoadAudit, Tool::AppointmentTimeline, Tool::PlanTravels]);
});
