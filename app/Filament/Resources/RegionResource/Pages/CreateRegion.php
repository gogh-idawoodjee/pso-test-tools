<?php

namespace App\Filament\Resources\RegionResource\Pages;

use App\Filament\Resources\CreateUserOwnedResource;
use App\Filament\Resources\RegionResource;

class CreateRegion extends CreateUserOwnedResource
{
    protected static string $resource = RegionResource::class;
}
