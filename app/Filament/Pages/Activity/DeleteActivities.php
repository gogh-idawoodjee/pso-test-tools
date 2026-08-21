<?php

namespace App\Filament\Pages\Activity;

use App\Enums\HttpMethod;
use App\Filament\BasePages\PSOActivityBasePage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use JsonException;

class DeleteActivities extends PSOActivityBasePage
{
    // Navigation
    protected static string|BackedEnum|null $activeNavigationIcon = 'heroicon-s-trash';

    protected static ?string $navigationLabel = 'Delete Activity';

    protected static ?string $title = 'Delete Activities';

    protected static ?string $slug = 'activity-delete';

    // View
    protected string $view = 'filament.pages.activity-delete-activities';

    public function activity_form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Activity Details')
                    ->icon('heroicon-s-arrow-path')
                    ->schema([
                        Forms\Components\Repeater::make('activities')
                            ->simple(
                                TextInput::make('activity_id')
                                    ->prefixIcon('heroicon-o-hashtag') // Good for ID/reference numbers
                                    ->label('Activity ID')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn ($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                            )
                            ->defaultItems(1)
                            ->addActionLabel('Add another activity')
                            ->reorderable(false),

                        Actions::make([Action::make('delete_activity')
                            ->action(function () {
                                $this->deleteActivities();
                            }),
                        ]),
                    ]),

            ])->statePath('activity_data');
    }

    /**
     * @throws JsonException
     */
    public function deleteActivities(): void
    {
        // clear the response
        $this->response = null;
        $this->validateForms($this->getForms());
        // Create the payload

        $payload = $this->buildPayload(
            required: [
                'activities' => collect($this->activity_data['activities'])->pluck('activity_id')->all(),
            ]
        );

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {

            $this->response = $this->sendToPSONew('activity', $tokenized_payload, [], HttpMethod::DELETE);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');

        }

    }
}
