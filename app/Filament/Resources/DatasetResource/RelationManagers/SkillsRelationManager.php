<?php

namespace App\Filament\Resources\DatasetResource\RelationManagers;

use App\Enums\ReferenceEntityType;

class SkillsRelationManager extends ReferenceValuesRelationManager
{
    public static function entityType(): ReferenceEntityType
    {
        return ReferenceEntityType::Skill;
    }
}
