<?php

namespace App\Filament\Resources\SkillResource\Pages;

use App\Filament\Resources\DatasetReferenceValueResource\Pages\ListDatasetReferenceValues;
use App\Filament\Resources\SkillResource;

class ListSkills extends ListDatasetReferenceValues
{
    protected static string $resource = SkillResource::class;
}
