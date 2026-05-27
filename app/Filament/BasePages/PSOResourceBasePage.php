<?php

namespace App\Filament\BasePages;

use App\Models\Environment;
use App\Traits\FormTrait;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;

abstract class PSOResourceBasePage extends Page
{
    use FormTrait, InteractsWithForms;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-user-group';

    protected static string|null|\BackedEnum $activeNavigationIcon = 'heroicon-s-user-group';

    protected static ?string $title = 'Resource Services';

    protected static ?string $slug = 'resource-services';

    //    protected static string $view = 'filament.pages.pso-resource';
    protected static string|null|\UnitEnum $navigationGroup = 'API Services';

    protected static ?string $navigationParentItem = 'Resource Services';

    public ?array $resource_data = [];

    protected function getForms(): array
    {
        return ['env_form', 'resource_form', 'json_form'];
    }

    public function mount(): void
    {

        $this->environments = Environment::with('datasets')->get();
        $this->fillForms($this->getForms());

    }
}
