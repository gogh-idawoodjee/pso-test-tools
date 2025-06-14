<?php

namespace App\Filament\BasePages;

use App\Models\Environment;
use App\Traits\FormTrait;
use Filament\Forms\Concerns\InteractsWithForms;

use Filament\Pages\Page;
use Override;


abstract class PSOActivityBasePage extends Page
{
    use InteractsWithForms, FormTrait;

    protected static ?string $navigationGroup = 'API Services';
    protected static ?string $navigationParentItem = 'Activity Services';

    public ?array $activity_data = [];

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $activeNavigationIcon = 'heroicon-s-document-text';

    protected static ?string $navigationLabel = 'Activity Services';
    protected static ?string $title = 'Activity Services';
    protected static ?string $slug = 'activity-services';

    protected static string $view = 'filament.pages.pso-activity';


    #[Override] protected function getForms(): array
    {
        return ['env_form', 'activity_form', 'json_form'];
    }


    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();

       $this->fillForms($this->getForms());
    }


}
