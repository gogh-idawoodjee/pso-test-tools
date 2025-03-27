<?php

namespace App\Filament\Pages;

use App\Enums\HttpMethod;

use App\Enums\TaskStatus;
use App\Models\Environment;
use App\Traits\FormTrait;
use App\Traits\PSOPayloads;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Filament\Forms;
use Illuminate\Support\Carbon;


class ActivityUpdateStatus extends Page
{

    use InteractsWithForms, FormTrait, PSOPayloads;


// View
    protected static string $view = 'filament.pages.activity-update-status';

// Navigation
    protected static ?string $navigationParentItem = 'Activity Services';
    protected static ?string $navigationGroup = 'Services';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $activeNavigationIcon = 'heroicon-s-arrow-path';

// Page Information
    protected static ?string $title = 'Update Activity Status';
    protected static ?string $slug = 'activity-status';

// Data
    public ?array $activity_data = [];


    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();
//        $this->selectedEnvironment = new Environment();
        $this->env_form->fill();
        $this->activity_form->fill();
    }


    protected function getForms(): array
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
                        TextInput::make('activity_id')
                            ->label('Activity ID')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
                        Select::make('status')
                            ->enum(TaskStatus::class)
                            ->options(TaskStatus::class)
                            ->required()
                            ->live(),
                        Forms\Components\DateTimePicker::make('datetimefixed')
                            ->label('Date Time Fixed'),
                        TextInput::make('resource_id')
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
                    ])->columns(),

            ])->statePath('activity_data');
    }

    public function updateTaskStatus(): void
    {
        // validate
        $this->env_form->getState();
        $this->activity_form->getState();
//        dd($this->TaskStatusPayload());

        $status = TaskStatus::from($this->activity_data['status'])->ishServicesValue();

        $this->response = $this->sendToPSO('activity/' . $this->activity_data['activity_id'] . '/' . $status, $this->TaskStatusPayload(), HttpMethod::PATCH);

    }


    private function TaskStatusPayload(): array
    {
        $payload = [

            'dataset_id' => $this->environment_data['dataset_id'],
            'base_url' => $this->selectedEnvironment->getAttribute('base_url'),
            'send_to_pso' => $this->environment_data['send_to_pso'],
            'account_id' => $this->selectedEnvironment->getAttribute('account_id'),
            'username' => $this->selectedEnvironment->getAttribute('username'),
            'password' => $this->selectedEnvironment->getAttribute('password'),
            'resource_id' => $this->activity_data['resource_id'],
            'activity_id' => $this->activity_data['activity_id'],
        ];

        if ($this->activity_data['datetimefixed']) {
            $payload['date_time_fixed'] = Carbon::parse($this->activity_data['datetimefixed'])->format('Y-m-d\TH:i');
        }
        return $payload;
    }
}
