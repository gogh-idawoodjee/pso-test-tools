<?php

namespace App\Filament\Pages\Activity;

use App\Enums\HttpMethod;
use App\Enums\TaskStatus;
use App\Filament\BasePages\PSOActivityBasePage;

use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\Carbon;
use JsonException;


class UpdateActivityStatus extends PSOActivityBasePage
{


// View
    protected static string $view = 'filament.pages.activity-update-status';

// Navigation

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $activeNavigationIcon = 'heroicon-s-arrow-path';
    protected static ?string $navigationLabel = 'Update Activity Status';

// Page Information
    protected static ?string $title = 'Update Activity Status';
    protected static ?string $slug = 'activity-status';


    public function activity_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Activity Details')
                    ->icon('heroicon-s-arrow-path')
                    ->schema([
                        TextInput::make('activity_id')
                            ->prefixIcon('heroicon-o-clipboard')
                            ->label('Activity ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        Select::make('status')
                            ->prefixIcon('heroicon-o-adjustments-horizontal')
                            ->enum(TaskStatus::class)
                            ->options(TaskStatus::class)
                            ->required()
                            ->live(),
                        Forms\Components\DateTimePicker::make('datetimefixed')
                            ->prefixIcon('heroicon-o-calendar')
                            ->label('Date Time Fixed'),

                        TextInput::make('resource_id')
                            ->prefixIcon('heroicon-o-user')
                            ->label('Resource ID')
                            ->helperText('Required if Status is Committed or higher')
                            ->required(static fn(Get $get) => $get('status') > 29)
                            ->validationMessages([
                                'required' => 'A resources is required for statuses Committed and higher'])
                            ->live(),
//                            ->hidden(static function (Get $get) {
//                                return $get('status') < 29;
//                            }),
                        Forms\Components\Actions::make([Forms\Components\Actions\Action::make('update_status')
                            ->action(function (Forms\Get $get, Forms\Set $set) {
                                $this->updateTaskStatus();

                            })
                        ]),

                    ])
                    ->columns(),

            ])->statePath('activity_data');
    }

    /**
     * @throws JsonException
     */
    public function updateTaskStatus(): void
    {
        $this->response = null;

        // validate
        $this->validateForms($this->getForms());

        $status = TaskStatus::from($this->activity_data['status'])->ishServicesValue();


        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $this->TaskStatusPayload())) {
            $this->response = $this->sendToPSO('activity/' . $this->activity_data['activity_id'] . '/' . $status, $tokenized_payload, HttpMethod::PATCH);
            $this->dispatch('open-modal', id: 'show-json');
        }

    }


    private function TaskStatusPayload(): array
    {

        $payload = array_merge($this->environnment_payload_data(),
            [
                'resource_id' => $this->activity_data['resource_id'],
                'activity_id' => $this->activity_data['activity_id'],
            ]);


        if ($this->activity_data['datetimefixed']) {
            $payload['date_time_fixed'] = Carbon::parse($this->activity_data['datetimefixed'])->format('Y-m-d\TH:i');
        }
        return $payload;
    }
}
