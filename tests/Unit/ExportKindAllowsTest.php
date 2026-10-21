<?php

use App\Enums\ExportKind;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\User;

it('says which scopes each kind may be stored on', function (ExportKind $kind, bool $onDataset, bool $onEnvironment) {
    expect($kind->allows(Dataset::class))->toBe($onDataset)
        ->and($kind->allows(Environment::class))->toBe($onEnvironment)
        ->and($kind->allows(User::class))->toBeTrue()
        ->and($kind->allows(stdClass::class))->toBeFalse();
})->with([
    'LOAD' => [ExportKind::Load, true, false],
    'Plan' => [ExportKind::Plan, true, false],
    'End of day' => [ExportKind::Eod, true, false],
    'Audit messages' => [ExportKind::Audits, true, false],
    'IFS event log' => [ExportKind::EventLog, true, false],
    'SYS' => [ExportKind::Sys, false, true],
]);

it('lists every kind for the Unfiled scope and keeps SYS apart from a dataset', function () {
    expect(ExportKind::forScope(User::class))->toBe(ExportKind::cases())
        ->and(ExportKind::forScope(Dataset::class))->not->toContain(ExportKind::Sys)
        ->and(ExportKind::forScope(Environment::class))->toBe([ExportKind::Sys]);
});

it('scopes the new kinds', function () {
    expect(ExportKind::PsoEvents->allows(Dataset::class))->toBeTrue()
        ->and(ExportKind::AppointmentHistory->allows(User::class))->toBeTrue()
        ->and(ExportKind::MessageTexts->allows(User::class))->toBeTrue()
        ->and(ExportKind::MessageTexts->allows(Dataset::class))->toBeFalse()
        ->and(ExportKind::MessageTexts->allows(Environment::class))->toBeFalse();
});

it('names the extensions and roots of the new kinds', function () {
    expect(ExportKind::PsoEvents->extensions())->toBe(['json', 'zip'])
        ->and(ExportKind::AppointmentHistory->extensions())->toBe(['csv', 'zip'])
        ->and(ExportKind::MessageTexts->extensions())->toBe(['xml', 'zip'])
        ->and(ExportKind::MessageTexts->expectedRoot())->toBe('DsSystemData')
        ->and(ExportKind::PsoEvents->expectedRoot())->toBeNull()
        ->and(ExportKind::PsoEvents->getLabel())->toBe('PSO system events')
        ->and(ExportKind::AppointmentHistory->getLabel())->toBe('Appointment history')
        ->and(ExportKind::MessageTexts->getLabel())->toBe('Message texts');
});
