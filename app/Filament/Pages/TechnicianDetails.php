<?php

namespace App\Filament\Pages;

use App\Enums\HttpMethod;
use App\Filament\BasePages\PSOResourceBasePage;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use JsonException;
use Override;

class TechnicianDetails extends PSOResourceBasePage
{


    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    // Page Information
    protected static ?string $title = 'Technician Details';
    protected static ?string $slug = 'resource-details';


    protected static string $view = 'filament.pages.technician-details';

    public bool $isAuthenticationRequired = true;

    public ?array $technician_list_data = [];
    public ?array $technician_list = [];
    public ?array $technician_details = [];
    public bool $loadingDetails = false;


    #[Override] protected function getForms(): array
    {
        return ['env_form', 'json_form', 'technicianListForm'];
    }


    public function technicianListForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Technician List')
                    ->schema(
                        [
                            Select::make('technician_list')
                                ->options(function () {
                                    return $this->technician_list;
                                })
                                ->placeholder('Click the Icon to Download the List')
                                ->native(false)
                                ->suffixIconColor('primary')
                                ->suffixAction(
                                    Action::make('getTechnicianList')
                                        ->label('Get List')
                                        ->icon('heroicon-o-arrow-down-on-square')
                                        ->action(function () {
                                            $this->getTechnicianList();
                                        })
                                )
                                ->afterStateUpdated(function ($livewire, $component, Set $set, ?string $state) {
                                    $livewire->validateOnly($component->getStatePath());
                                    $this->getTechnicianDetails($state);
                                })
                                ->hint(new HtmlString(Blade::render('<x-filament::loading-indicator class="h-5 w-5" wire:loading wire:target="loadingDetails" />')))
                                ->required()
                                ->live()
                        ]
                    )
            ])->statePath('technician_list_data');

    }

    /**
     * @throws JsonException
     */
    private function getTechnicianDetails($state): void // Make this private
    {
        $payload = array_merge($this->environnment_payload_data(), []);

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {
            $this->response = $this->sendToPSONew('resource/' . $state, null, $tokenized_payload, HttpMethod::GET, true);
            $this->technician_details = data_get(json_decode($this->response, true, 512, JSON_THROW_ON_ERROR), 'data.resource', []);
            $this->json_form_data['json_response_pretty'] = $this->response;

//            $this->dispatch('open-modal', id: 'show-json');
        }
    }

    /**
     * @throws JsonException
     */
    public function getTechnicianList(): void
    {
        $this->response = null;
        $this->validateForms(['env_form']);
        $payload = array_merge($this->environnment_payload_data(),
            [

            ]);

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {

            $this->response = $this->sendToPSONew('resource', null, $tokenized_payload, HttpMethod::GET, true);
            $this->technician_list = data_get(json_decode($this->response, true, 512, JSON_THROW_ON_ERROR), 'data.resources', []);


        }

    }
}
