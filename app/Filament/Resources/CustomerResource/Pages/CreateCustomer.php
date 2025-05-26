<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CreateUserOwnedResource;
use App\Filament\Resources\CustomerResource;


class CreateCustomer extends CreateUserOwnedResource
{
    protected static string $resource = CustomerResource::class;
}
