<?php

namespace App\Filament\BasePages;

use App\Models\Environment;
use App\Traits\FormTrait;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

abstract class PSOActivityBasePage extends Page
{
    use FormTrait;

    protected static string|null|UnitEnum $navigationGroup = 'API Services';

    protected static ?string $navigationParentItem = 'Activity Services';

    public ?array $activity_data = [];

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-document-text';

    protected static string|null|BackedEnum $activeNavigationIcon = 'heroicon-s-document-text';

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
