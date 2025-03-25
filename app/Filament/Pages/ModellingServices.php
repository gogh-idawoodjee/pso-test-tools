<?php

namespace App\Filament\Pages;

use App\Models\Environment;
use App\Traits\FormTrait;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;

class ModellingServices extends Page
{
    use InteractsWithForms, FormTrait;

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';
    protected static ?string $activeNavigationIcon = 'heroicon-s-cube-transparent';

    protected static ?string $navigationGroup = 'Services';
    protected static ?string $navigationLabel = 'Modelling Services';
    protected static ?string $title = 'Modelling Services';
    protected static ?string $slug = 'modelling-services';

    protected static string $view = 'filament.pages.modelling-services';

    protected function getForms(): array
    {
        return ['env_form'];
    }

    public function mount(): void
    {

        $this->environments = Environment::with('datasets')->get();


    }
}
