<?php

namespace App\Filament\Pages\Resource;


use App\Enums\HttpMethod;
use App\Filament\BasePages\PSOResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use JsonException;


class ResourceGetDetails extends PSOResource
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
                                $this->dispatch('open-modal', id: 'show-json');
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
        $this->validateForms($this->getForms());

        $env_payload = $this->environnment_payload_data();

        $this->response = $this->sendToPSO('resource/' . $this->resource_data['resource_id'], $env_payload, HttpMethod::GET);

    }
}
