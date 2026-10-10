<?php

namespace App\Filament\Resources\SlaTypeResource\Pages;

use App\Filament\Resources\DatasetReferenceValueResource\Pages\ListDatasetReferenceValues;
use App\Filament\Resources\SlaTypeResource;

class ListSlaTypes extends ListDatasetReferenceValues
{
    protected static string $resource = SlaTypeResource::class;
}
