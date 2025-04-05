<?php

namespace App\Filament\Pages\Resource;


use App\Enums\HttpMethod;
use App\Filament\BasePages\PSOResourceBasePage;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use JsonException;


class ResourceGetDetails extends PSOResourceBasePage
{

    protected static ?string $title = 'Get Resource Details';
    protected static ?string $slug = 'resource-details';


    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.resource-get-details';

    public function resource_form(Form $form): Form
    {

        return $form
            ->schema([

                Section::make('Resource')
                    ->schema([
                        TextInput::make('resource_id')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Resource ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        Actions::make([Actions\Action::make('get_resource')
                            ->action(function () {
                                $this->getResource();
                            })
                        ]),
                    ])
            ])
            ->statePath('resource_data');
    }

    /**
     * @throws JsonException
     */
    public function getResource(): void
    {
        $this->response = null;
        $this->validateForms($this->getForms());

        if ($this->setupPayload($this->environment_data['send_to_pso'], $this->environnment_payload_data())) {
            $this->response = $this->sendToPSO('resource/' . $this->resource_data['resource_id'], $this->environnment_payload_data(), HttpMethod::GET);
            $this->dispatch('open-modal', id: 'show-json');
        }

    }
}
