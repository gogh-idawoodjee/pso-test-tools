<?php

namespace App\Filament\BasePages;

use App\Models\Environment;
use App\Traits\FormTrait;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

abstract class ModellingBasePage extends Page
{
    use FormTrait;

    protected static string|UnitEnum|null $navigationGroup = 'API Services';

    protected static ?string $navigationParentItem = 'Modelling Services';

    public ?array $modelling_data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|BackedEnum|null $activeNavigationIcon = 'heroicon-s-document-text';

    protected static ?string $navigationLabel = 'Modelling Services';

    protected static ?string $title = 'Modelling Services';

    protected static ?string $slug = 'modelling-services';

    protected string $view = 'filament.pages.modelling-services';

    protected function getForms(): array
    {
        return ['env_form', 'modelling_form'];
    }

    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();

        $this->fillForms($this->getForms());
    }
}
