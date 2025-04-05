<?php

namespace App\Filament\Pages\Resource;

use App\Enums\HttpMethod;
use App\Filament\BasePages\PSOResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;


class ResourceDeleteUnavailability extends PSOResource
{

    protected static ?string $title = 'Delete Unavailablity';
    protected static ?string $slug = 'resource-delete-unavailability';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.resource-delete-unavailability';

    public function resource_form(Form $form): Form
    {

        return $form
            ->schema([
                // todo update API to make this a multi
                Section::make('Unavailability')
                    ->schema([
                        TextInput::make('unavailability_id')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Unavailability ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        Actions::make([Actions\Action::make('delete_unavailability')
                            ->action(function () {
                                $this->deleteUnavailability();
                            })
                        ]),
                    ])
            ])
            ->statePath('resource_data');
    }

    public function deleteUnavailability(): void
    {
        $this->validateForms($this->getForms());

        $env_payload = $this->environnment_payload_data();


        $this->response = $this->sendToPSO('unavailability/' . $this->resource_data['unavailability_id'], $env_payload, HttpMethod::DELETE);

    }

}
