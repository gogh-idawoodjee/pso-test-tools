<?php

use App\Filament\Navigation\Groups;
use App\Filament\Pages;
use App\Filament\Resources;
use App\Models\User;
use Filament\Navigation\NavigationGroup;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('puts every page in one of the four intent groups', function (string $class, string $group) {
    expect($class::getNavigationGroup())->toBe($group);
})->with([
    [Pages\Tools\LoadAuditEntry::class, Groups::ANALYSE],
    [Pages\SysFileCompare::class, Groups::ANALYSE],
    [Pages\FilterLoadFile::class, Groups::ANALYSE],
    [Pages\PreferenceCalculator::class, Groups::ANALYSE],
    [Pages\HomePages\PSOActivityHome::class, Groups::LIVE],
    [Pages\HomePages\PSOResourceHome::class, Groups::LIVE],
    [Pages\HomePages\PSOModellingHome::class, Groups::LIVE],
    [Pages\Activity\UpdateActivityStatus::class, Groups::LIVE],
    [Pages\Resource\ResourceEvent::class, Groups::LIVE],
    [Pages\Modelling\ARPRegion::class, Groups::LIVE],
    [Pages\TechnicianDetails::class, Groups::LIVE],
    [Pages\GenericDelete::class, Groups::LIVE],
    [Pages\GenerateCustomException::class, Groups::LIVE],
    [Pages\TravelAnalyzer::class, Groups::LIVE],
    [Resources\EnvironmentResource::class, Groups::SETUP],
    [Resources\DatasetResource::class, Groups::SETUP],
    [Resources\DatasetReferenceValueResource::class, Groups::SETUP],
    [Resources\TokenUsageLogResource::class, Groups::ADMIN],
    [Pages\Backups::class, Groups::ADMIN],
    [Pages\HealthCheckResults::class, Groups::ADMIN],
    [Pages\IssueToken::class, Groups::ADMIN],
]);

it('orders the groups analyse, live, setup, admin', function () {
    $labels = collect(filament()->getPanel('app')->getNavigationGroups())
        ->map(fn ($group) => $group instanceof NavigationGroup ? $group->getLabel() : $group)->values()->all();
    expect($labels)->toBe([Groups::ANALYSE, Groups::LIVE, Groups::SETUP, Groups::ADMIN]);
});

it('no longer uses the old group names anywhere', function () {
    $hits = shell_exec("grep -rln \"'Base Data'\\|'API Services'\\|'Additional Tools'\\|'Core'\" app resources/views || true");
    expect(trim((string) $hits))->toBe('');
});
