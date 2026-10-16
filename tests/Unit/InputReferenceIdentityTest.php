<?php

use App\Support\ScheduleData\InputReferenceIdentity;
use Carbon\CarbonImmutable;

it('describes a Load from File upload in one plain line with the time in UTC', function () {
    $identity = InputReferenceIdentity::forLoadFromFile('SaskTel TST',
        'north_load.xml',
        CarbonImmutable::parse('2026-10-16 09:40:12', 'America/Regina'),
    );

    expect($identity->userId)->toBe('PSO Test Tools');
    expect($identity->description)->toBe('Sent from PSO Test Tools (Load from File), environment SaskTel TST, file north_load.xml, 2026-10-16 15:40Z');
    expect($identity->description)->not->toContain("\u{2014}");
});

it('keeps the values on one line of valid UTF-8', function () {
    $identity = new InputReferenceIdentity("PSO\tTest Tools", "line one\r\nline two \x00 \xFF end");

    expect($identity->userId)->toBe('PSO Test Tools');
    expect($identity->description)->toBe('line one line two ? end');
    expect(mb_check_encoding($identity->description, 'UTF-8'))->toBeTrue();
});

it('caps the description at 255 characters by shortening a long file name in the middle, keeping its extension', function () {
    $fileName = 'north_tr'.str_repeat('x', 288).'1005.xml';
    expect(mb_strlen($fileName))->toBe(304);

    $identity = InputReferenceIdentity::forLoadFromFile('SaskTel TST', $fileName, CarbonImmutable::parse('2026-10-16 13:40:00', 'UTC'));

    $prefix = 'Sent from PSO Test Tools (Load from File), environment SaskTel TST, file ';
    $suffix = ', 2026-10-16 13:40Z';
    $fileBudget = 255 - mb_strlen($prefix) - mb_strlen($suffix);
    $keep = $fileBudget - 3 - 4;

    expect(mb_strlen($identity->description))->toBe(255);
    expect($identity->description)->toBe($prefix.'north_tr'.str_repeat('x', (int) ceil($keep / 2) - 8).'...'.str_repeat('x', intdiv($keep, 2) - 4).'1005.xml'.$suffix);
});

it('shortens the environment name too when the file name alone cannot make room', function () {
    $identity = InputReferenceIdentity::forLoadFromFile(str_repeat('E', 300), str_repeat('f', 300).'.json', CarbonImmutable::parse('2026-10-16 13:40:00', 'UTC'));

    expect(mb_strlen($identity->description))->toBeLessThanOrEqual(255);
    expect($identity->description)
        ->toStartWith('Sent from PSO Test Tools (Load from File), environment EEE')
        ->toContain('...')
        ->toContain('.json, 2026-10-16 13:40Z')
        ->toEndWith(', 2026-10-16 13:40Z');
});

it('leaves a description that fits alone', function () {
    $identity = InputReferenceIdentity::forLoadFromFile('TST', str_repeat('f', 100).'.xml', CarbonImmutable::parse('2026-10-16 13:40:00', 'UTC'));

    expect($identity->description)->toBe('Sent from PSO Test Tools (Load from File), environment TST, file '.str_repeat('f', 100).'.xml, 2026-10-16 13:40Z');
});
