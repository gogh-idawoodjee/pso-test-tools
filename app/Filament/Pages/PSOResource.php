<?php

namespace App\Filament\Pages;

use App\Enums\ProcessType;
use App\Models\Environment;
use App\Traits\FormTrait;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Pages\Page;


class PSOResource extends Page
{
    use InteractsWithForms, FormTrait;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $activeNavigationIcon = 'heroicon-s-user-group';
    protected static ?string $navigationGroup = 'Services';

    protected static ?string $navigationLabel = 'Resource Services';
    protected static ?string $title = 'Resource Services';
    protected static ?string $slug = 'resource-services';

    protected static string $view = 'filament.pages.pso-resource';


    protected function getForms(): array
    {
        return ['env_form', 'resource_form'];
    }

    public function mount(): void
    {

        $this->environments = Environment::with('datasets')->get();

    }

    public function resource_form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('resource_tabs')->tabs([
                    Tab::make('get_details_tab')
                        ->schema([])
                        ->icon('heroicon-o-user-circle')
                        ->label('Get Resource Details'),
                    Tab::make('resource_event_tab')
                        ->schema([
                            Toggle::make('send_to_pso')
                                ->dehydrated(false)
                                ->label('Send to PSO')
                                ->live(),
                            Toggle::make('keep_pso_data')
                                ->dehydrated(false)
                                ->label('Keep PSO Data')
                                ->requiredIf('send_to_pso', true)
                                ->disabled(static function (Get $get) {
                                    return !$get('send_to_pso');
                                }),
                            TextInput::make('dse_duration')
                                ->dehydrated(false)
                                ->label('DSE Duration')
                                ->integer()
                                ->minValue(3)
                                ->placeholder(3)
                                ->prefixIcon('heroicon-o-cube-transparent'),
                            TextInput::make('appointment_window')
                                ->dehydrated(false)
                                ->label('Appointment Window')
                                ->integer()
                                ->minValue(7)
                                ->placeholder(7)
                                ->prefixIcon('heroicon-o-calendar-date-range'),
                            Select::make('process_type')
                                ->enum(ProcessType::class)
                                ->dehydrated(false)
                                ->options(ProcessType::class)
                                ->prefixIcon('heroicon-o-adjustments-horizontal'),
                            DateTimePicker::make('datetime')
                                ->dehydrated(false)
                                ->label('Input Date Time')
                                ->prefixIcon('heroicon-o-clock'),
                            Actions::make([Actions\Action::make('push_it')
                                ->action(function (Get $get, Set $set) {
//                                $set('excerpt', str($get('content'))->words(45, end: ''));
                                    // the update status thingy
                                    $this->deleteActivity();

                                })->label('Push it real good'),
                            ])
                        ])->columns()
                        ->icon('heroicon-o-exclamation-triangle')
                        ->label(' Event Generation'),
                    Tab::make('unavailability_generation_tab')
                        ->schema([])
                        ->icon('heroicon-o-no-symbol')
                        ->label(' Unavailability Generation'),
                    Tab::make('unavailability_deleten_tab')
                        ->schema([])
                        ->icon('heroicon-o-trash')
                        ->label('Delete Unavailability'),
                    Tab::make('unavailability_update_tab')
                        ->schema([])
                        ->icon('heroicon-o-calendar')
                        ->label('Update Unavailability'),
                    Tab::make('update_shift_tab')
                        ->schema([])
                        ->icon('heroicon-o-calendar-date-range')
                        ->label('Update Shift'),

                ])
            ]);
    }
}
