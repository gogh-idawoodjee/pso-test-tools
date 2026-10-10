<?php

namespace App\Filament\Resources\RegionResource\Pages;

use App\Filament\Resources\DatasetReferenceValueResource\Pages\ListDatasetReferenceValues;
use App\Filament\Resources\RegionResource;

class ListRegions extends ListDatasetReferenceValues
{
    protected static string $resource = RegionResource::class;
}
