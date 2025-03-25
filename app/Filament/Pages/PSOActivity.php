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


class PSOActivity extends Page
{
    use InteractsWithForms, FormTrait;

    protected static ?string $navigationGroup = 'Services';


    public ?array $activity_data = [];

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $activeNavigationIcon = 'heroicon-s-document-text';

    protected static ?string $navigationLabel = 'Activity Services';
    protected static ?string $title = 'Activity Services';
    protected static ?string $slug = 'activity-services';


    protected static string $view = 'filament.pages.pso-activity';


    protected function getForms(): array
    {
        return ['env_form', 'activity_form'];
    }

    public function mount(): void
    {

        $this->environments = Environment::with('datasets')->get();


    }


    public function activity_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Activity Details')
                    ->icon('heroicon-s-document-text')
                    ->schema([

                        TextInput::make('activity_id')
                            ->label('Activity ID')
                            ->required(function (Get $get) {
                                return !$get('activities');
                            }),
                        Forms\Components\Tabs::make('activity_tabs')->tabs([
                            Forms\Components\Tabs\Tab::make('updatestatus_tab')
                                ->label('Update Status')
                                ->icon('heroicon-o-arrow-path')
                                ->schema([
                                    Select::make('status')
                                        ->enum(TaskStatus::class)
                                        ->options(TaskStatus::class)
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
                                        ->live()
                                        ->hidden(static function (Get $get) {
                                            return $get('status') < 29;
                                        }),
                                    Forms\Components\Actions::make([Forms\Components\Actions\Action::make('update_status')
                                        ->action(function (Forms\Get $get, Forms\Set $set) {
//                                $set('excerpt', str($get('content'))->words(45, end: ''));
                                            // the update status thingy
                                            $this->updateTaskStatus();

                                        })
                                    ])->columnSpan(['xl' => 3]),
                                ]),
                            Forms\Components\Tabs\Tab::make('delete_sla_tab')
                                ->label('Delete SLA')
                                ->icon('heroicon-o-document-minus')
                                ->schema([
                                    Toggle::make('start_based'),
                                    TextInput::make('sla_type_id')
                                        ->required()
                                        ->label('SLA Type ID'),
                                    Forms\Components\Actions::make([Forms\Components\Actions\Action::make('delete_sla')
                                        ->action(function (Forms\Get $get, Forms\Set $set) {
//                                $set('excerpt', str($get('content'))->words(45, end: ''));
                                            // the update status thingy
                                            $this->deleteSLA();

                                        })->label('Delete SLA'),
                                    ])->columns(3),
                                ]),
                            Forms\Components\Tabs\Tab::make('delete_activity_tab')
                                ->label('Delete Activity')
                                ->icon('heroicon-o-trash')
                                ->schema([
                                    Forms\Components\Actions::make([Forms\Components\Actions\Action::make('delete_activity')
                                        ->action(function (Forms\Get $get, Forms\Set $set) {
//                                $set('excerpt', str($get('content'))->words(45, end: ''));
                                            // the update status thingy
                                            $this->deleteActivity();

                                        })->label('Delete Activity'),
                                    ]),
                                ]),
                        ])->contained(false),

                    ]),
                Forms\Components\Tabs::make('activity_tabs')->tabs([

                    Forms\Components\Tabs\Tab::make('generate_acitivities_tab')
                        ->label('Generate Activities')
                        ->icon('heroicon-o-document-duplicate')
                        ->schema([]),
                    Forms\Components\Tabs\Tab::make('delete_acitivities_tab')
                        ->label('Delete Multiple Activities')
                        ->icon('heroicon-o-trash')
                        ->schema([]),

                ])->contained(true),
            ])->statePath('activity_data');
    }

    public function updateTaskStatus(): void
    {
        dd($this->activity_form->getState());
    }

    public function deleteSLA(): void
    {
        dd($this->activity_form->getState());
    }

}
