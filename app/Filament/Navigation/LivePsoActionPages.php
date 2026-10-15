<?php

namespace App\Filament\Navigation;

use App\Filament\Pages;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;
use Filament\Pages\Page;

/** Pages whose actions change a live PSO environment; each shows the warning banner. */
final class LivePsoActionPages
{
    /** @return list<class-string<Page>> */
    public static function all(): array
    {
        return [
            Pages\HomePages\PSOActivityHome::class,
            Pages\HomePages\PSOResourceHome::class,
            Pages\HomePages\PSOModellingHome::class,
            Pages\Activity\DeleteActivities::class,
            Pages\Activity\GenerateActivities::class,
            Pages\Activity\UpdateActivityStatus::class,
            Pages\Resource\ResourceEvent::class,
            Pages\Resource\ResourceUnavailability::class,
            Pages\Resource\ResourceUpdateShift::class,
            Pages\Resource\ResourceUpdateUnavailability::class,
            Pages\Modelling\ARPRegion::class,
            Pages\Modelling\ARPResource::class,
            Pages\TechnicianDetails::class,
            Pages\GenericDelete::class,
            Pages\GenerateCustomException::class,
            Pages\TravelAnalyzer::class,
            EnvironmentTools::class,
        ];
    }
}
