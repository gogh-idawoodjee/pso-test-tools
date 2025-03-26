<?php

namespace App\Filament\Pages;

use App\Enums\TaskStatus;
use App\Models\Environment;
use App\Traits\FormTrait;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Filament\Forms;
use phpDocumentor\Reflection\DocBlock\Tags\Method;

class ActivityUpdateStatus extends Page
{

    use InteractsWithForms, FormTrait;


    protected static string $view = 'filament.pages.activity-update-status';
    protected static ?string $navigationParentItem = 'Activity Services';

    protected static ?string $navigationGroup = 'Services';

    public ?array $activity_data = [];

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $activeNavigationIcon = 'heroicon-s-arrow-path';

    protected static ?string $title = 'Update Activity Status';

    protected static ?string $slug = 'activity-status';


    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();
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
                            ->afterStateUpdated(function ($livewire, $component) {
                                $livewire->validateOnly($component->getStatePath());
                            }),

                        Select::make('status')
                            ->enum(TaskStatus::class)
                            ->options(TaskStatus::class)
                            ->required()
                            ->live(),
                        Forms\Components\DateTimePicker::make('datetimefixed')
                            ->label('Date Time Fixed'),
                        TextInput::make('resource_id')
                            ->label('Resource')
                            ->required(static function (Get $get) {
                                return $get('status') > 29;
                            })
                            ->validationMessages([
                                'required' => 'A resources is required for statuses Committed and higher'])
                            ->live(),
//                            ->hidden(static function (Get $get) {
//                                return $get('status') < 29;
//                            }),
                        Forms\Components\Actions::make([Forms\Components\Actions\Action::make('update_status')
                            ->action(function (Forms\Get $get, Forms\Set $set) {
//                                $set('excerpt', str($get('content'))->words(45, end: ''));
                                // the update status thingy
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
//        dd($this->environment_data);
    }


    private function TaskStatusPayload($data)
    {
        $schema = [
            'base_url' => $data('base_url'),
            'dataset_id' => $data('dataset_id'),
//            'send_to_pso' => $data('send_to_pso'),
            'account_id' => $data('account_id'),
            'username' => $data('username'),
            'password' => $data('password'),
            'resource_id' => $data('resource_id'),
            'status' => $data('status'),
            'activity_id' => $data('activity_id'),
        ];
    }
}
