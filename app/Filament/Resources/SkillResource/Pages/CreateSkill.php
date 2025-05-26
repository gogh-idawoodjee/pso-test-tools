<?php

namespace App\Filament\Resources\SkillResource\Pages;

use App\Filament\Resources\CreateUserOwnedResource;
use App\Filament\Resources\SkillResource;

class CreateSkill extends CreateUserOwnedResource
{
    protected static string $resource = SkillResource::class;
}
