<?php

namespace App\Filament\Pages\Activity;

use App\Enums\HttpMethod;
use App\Filament\BasePages\PSOActivityBasePage;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Illuminate\Support\Arr;
use JsonException;


class DeleteActivities extends PSOActivityBasePage
{

// Navigation
    protected static ?string $activeNavigationIcon = 'heroicon-s-trash';
    protected static ?string $navigationLabel = 'Delete Activity';

    protected static ?string $title = 'Delete Activities';
    protected static ?string $slug = 'activity-delete';

// View
    protected static string $view = 'filament.pages.activity-delete-activities';

    /**
     * @param array $activity_list
     * @return array
     */
    public function getPayload(array $activity_list): array
    {

        return Arr::add($this->environnment_payload_data(), 'activities', $activity_list);
//        return [
//
//            'dataset_id' => $this->environment_data['dataset_id'],
//            'base_url' => $this->selectedEnvironment->getAttribute('base_url'),
//            'send_to_pso' => $this->environment_data['send_to_pso'],
//            'account_id' => $this->selectedEnvironment->getAttribute('account_id'),
//            'username' => $this->selectedEnvironment->getAttribute('username'),
//            'password' => $this->selectedEnvironment->getAttribute('password'),
//            'activities' => $activity_list,
//        ];
    }

    public function activity_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Activity Details')
                    ->icon('heroicon-s-arrow-path')
                    ->schema([
                        Forms\Components\Repeater::make('activities')
                            ->simple(
                                TextInput::make('activity_id')
                                    ->prefixIcon('heroicon-o-clipboard')
                                    ->label('Activity ID')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                            )
                            ->defaultItems(1)
                            ->addActionLabel('Add another activity')
                            ->reorderable(false),

                        Forms\Components\Actions::make([Forms\Components\Actions\Action::make('delete_activity')
                            ->action(function () {
                                $this->deleteActivities();
                                $this->dispatch('open-modal', id: 'show-json');
                            })
                        ]),
                    ]),

            ])->statePath('activity_data');
    }

    /**
     * @throws JsonException
     */
    public function deleteActivities(): void
    {
        $this->validateForms($this->getForms());

        $activity_list = collect($this->activity_data['activities'])->pluck('activity_id')->all();

        $payload = $this->getPayload($activity_list);

        // todo, I the payload is bigger than this no?

        $this->response = $this->sendToPSO('activity', $payload, HttpMethod::DELETE);

    }
}
