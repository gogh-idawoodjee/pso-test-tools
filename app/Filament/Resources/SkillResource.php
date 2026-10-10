<?php

namespace App\Filament\Resources;

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\SkillResource\Pages\ListSkills;

class SkillResource extends DatasetReferenceValueResource
{
    public static function entityType(): ReferenceEntityType
    {
        return ReferenceEntityType::Skill;
    }

    public static function getPages(): array
    {
        return ['index' => ListSkills::route('/')];
    }
}
