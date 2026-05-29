<?php

namespace App\Filament\BasePages;

use App\Models\Environment;
use App\Traits\FormTrait;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

abstract class PSOResourceBasePage extends Page
{
    use FormTrait;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::UserGroup;

    protected static ?string $title = 'Resource Services';

    protected static ?string $slug = 'resource-services';

    protected string $view = 'filament.pages.pso-resource';

    protected static string|UnitEnum|null $navigationGroup = 'API Services';

    protected static ?string $navigationParentItem = 'Resource Services';

    public ?array $resource_data = [];

    protected function getForms(): array
    {
        return ['env_form', 'resource_form'];
    }

    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();
        $this->fillForms($this->getForms());
    }
}
