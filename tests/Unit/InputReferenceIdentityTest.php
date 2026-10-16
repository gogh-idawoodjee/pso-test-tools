<?php

use App\Support\ScheduleData\InputReferenceIdentity;
use Carbon\CarbonImmutable;

it('describes a Load from File upload in one plain line with the time in UTC', function () {
    $identity = InputReferenceIdentity::forLoadFromFile(
        'Ish Dawoodjee',
        'SaskTel TST',
        'north_load.xml',
        CarbonImmutable::parse('2026-10-16 09:40:12', 'America/Regina'),
    );

    expect($identity->userId)->toBe('PSO Test Tools');
    expect($identity->description)->toBe('Sent from PSO Test Tools (Load from File) by Ish Dawoodjee, environment SaskTel TST, file north_load.xml, 2026-10-16 15:40Z');
    expect($identity->description)->not->toContain("\u{2014}");
});

it('keeps the values on one line of valid UTF-8', function () {
    $identity = new InputReferenceIdentity("PSO\tTest Tools", "line one\r\nline two \x00 \xFF end");

    expect($identity->userId)->toBe('PSO Test Tools');
    expect($identity->description)->toBe('line one line two ? end');
    expect(mb_check_encoding($identity->description, 'UTF-8'))->toBeTrue();
});
