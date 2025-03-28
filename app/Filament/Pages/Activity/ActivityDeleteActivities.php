<?php

namespace App\Filament\Pages\Activity;

use App\Enums\HttpMethod;
use App\Models\Environment;
use App\Traits\FormTrait;
use App\Traits\PSOPayloads;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use JsonException;
use Override;

class ActivityDeleteActivities extends Page
{

    use InteractsWithForms, FormTrait, PSOPayloads;

// Navigation
    protected static ?string $navigationParentItem = 'Activity Services';
    protected static ?string $navigationGroup = 'Services';
    protected static ?string $navigationIcon = 'heroicon-o-trash';
    protected static ?string $activeNavigationIcon = 'heroicon-s-trash';

    protected static ?string $title = 'Delete Activities';
    protected static ?string $slug = 'activity-delete';

// View
    protected static string $view = 'filament.pages.activity-delete-activities';

    public ?array $activity_data = [];

    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();

        $this->env_form->fill();
        $this->activity_form->fill();
    }

    /**
     * @param array $activity_list
     * @return array
     */
    public function getPayload(array $activity_list): array
    {
        $payload = [

            'dataset_id' => $this->environment_data['dataset_id'],
            'base_url' => $this->selectedEnvironment->getAttribute('base_url'),
            'send_to_pso' => $this->environment_data['send_to_pso'],
            'account_id' => $this->selectedEnvironment->getAttribute('account_id'),
            'username' => $this->selectedEnvironment->getAttribute('username'),
            'password' => $this->selectedEnvironment->getAttribute('password'),
            'activities' => $activity_list,
        ];
        return $payload;
    }

    #[Override] protected function getForms(): array
    {
        return ['env_form', 'activity_form'];
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

        $this->response = $this->sendToPSO('activity', $payload, HttpMethod::DELETE);

    }
}
