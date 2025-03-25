<?php

namespace App\Filament\Pages;

use App\Enums\TaskStatus;



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
    use InteractsWithForms;



    public ?array $data = [];
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Activity Services';
    protected static ?string $title = 'Activity Services';
    protected static ?string $slug = 'activity-services';


    protected static string $view = 'filament.pages.pso-activity';

    protected function getForms(): array
    {
        return ['env_form', 'activity_form'];
    }

    public function env_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Environment')
                    ->icon('heroicon-o-circle-stack')
                    ->schema([]),

//                Fieldset::make('Delete Skill'),

            ]);

    }

    public function activity_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Activity Details')
                    ->schema([
                        Toggle::make('send_to_pso')->inline(false),
                        TextInput::make('activity_id')
                            ->label('Activity ID')
                            ->required(),
                    ])->columns(),
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
                        ->schema([]),
                    Forms\Components\Tabs\Tab::make('generate_acitivities_tab')
                        ->label('Generate Activities')
                        ->icon('heroicon-o-document-duplicate')
                        ->schema([]),
                    Forms\Components\Tabs\Tab::make('delete_activity_tab')
                        ->label('Delete Activity')
                        ->icon('heroicon-o-trash')
                        ->schema([])
                ]),
            ])->statePath('data');
    }

    public function updateTaskStatus(): void
    {
        dd($this->activity_form->getState());
    }


}
