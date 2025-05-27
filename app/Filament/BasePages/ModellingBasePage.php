<?php

namespace App\Filament\BasePages;

use App\Models\Environment;
use App\Traits\FormTrait;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;
use Override;


abstract class ModellingBasePage extends Page
{
    use InteractsWithForms, FormTrait;

    protected static ?string $navigationGroup = 'API Services';
    protected static ?string $navigationParentItem = 'Modelling Services';

    public ?array $modelling_data = [];

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $activeNavigationIcon = 'heroicon-s-document-text';

    protected static ?string $navigationLabel = 'Modelling Services';
    protected static ?string $title = 'Modelling Services';
    protected static ?string $slug = 'modelling-services';


    protected static string $view = 'filament.pages.modelling-services';


    #[Override] protected function getForms(): array
    {
        return ['env_form', 'modelling_form', 'json_form'];
    }


    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();

        $this->fillForms($this->getForms());
    }


}
