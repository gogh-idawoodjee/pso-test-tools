<?php

namespace App\Filament\Pages\Tools;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\ExportFile;
use Livewire\Attributes\Locked;

class PlanTravelsEntry extends ToolWizardPage
{
    /** The first date this page last filled in from a plan export; a different value is the user's and is kept. */
    #[Locked]
    public ?string $suggestedFirstDate = null;

    public static function tool(): ToolId
    {
        return ToolId::PlanTravels;
    }

    /** The first date follows the chosen plan export (its Input_Reference day in the dataset zone) until the user types one. */
    protected function afterInputsChanged(): void
    {
        $fileId = $this->data['files'][ExportKind::Plan->value] ?? null;
        $date = blank($fileId) ? null : ExportFile::query()->find($fileId)?->exportDate();
        $current = $this->data['options']['first_date'] ?? null;

        if ($date !== null && (blank($current) || $current === $this->suggestedFirstDate)) {
            $this->data['options']['first_date'] = $date;
            $this->suggestedFirstDate = $date;
        }
    }
}
