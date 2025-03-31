<?php

namespace App\Filament\BasePages;


use App\Models\Environment;
use App\Traits\FormTrait;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Pages\Page;


class PSOResource extends Page
{
    use InteractsWithForms, FormTrait;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $activeNavigationIcon = 'heroicon-s-user-group';

    protected static ?string $title = 'Resource Services';
    protected static ?string $slug = 'resource-services';

    protected static string $view = 'filament.pages.pso-resource';

    protected static ?string $navigationGroup = 'API Services';
    protected static ?string $navigationParentItem = 'Resource Services';


    public ?array $resource_data = [];


    #[\Override] protected function getForms(): array
    {
        return ['env_form', 'resource_form'];
    }

    public function mount(): void
    {

        $this->environments = Environment::with('datasets')->get();
//        $this->selectedEnvironment = new Environment();
        $this->env_form->fill();
        $this->resource_form->fill();

    }




}
