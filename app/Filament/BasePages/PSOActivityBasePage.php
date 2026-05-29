<?php

namespace App\Filament\BasePages;

use App\Models\Environment;
use App\Traits\FormTrait;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

abstract class PSOActivityBasePage extends Page
{
    use FormTrait;

    protected static string|UnitEnum|null $navigationGroup = 'API Services';

    protected static ?string $navigationParentItem = 'Activity Services';

    public ?array $activity_data = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::DocumentText;

    protected static ?string $navigationLabel = 'Activity Services';

    protected static ?string $title = 'Activity Services';

    protected static ?string $slug = 'activity-services';

    protected string $view = 'filament.pages.pso-activity';

    protected function getForms(): array
    {
        return ['env_form', 'activity_form'];
    }

    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();

        $this->fillForms($this->getForms());
    }
}
