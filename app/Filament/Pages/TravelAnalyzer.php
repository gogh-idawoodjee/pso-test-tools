<?php

namespace App\Filament\Pages;


use App\Models\Environment;
use App\Traits\FormTrait;
use App\Traits\GeocCodeTrait;
use App\Traits\PSOInteractionsTrait;
use Filament\Forms;
use Filament\Forms\Components\Section;

use Filament\Forms\Components\TextInput;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;

use Filament\Notifications\Notification;
use Filament\Pages\Page;


class TravelAnalyzer extends Page
{

    use InteractsWithForms, FormTrait, GeocCodeTrait, PSOInteractionsTrait;

    protected static ?string $navigationIcon = 'heroicon-o-map';
    protected static ?string $navigationGroup = 'Additional Tools';

    public ?array $data = [];
    protected static ?string $activeNavigationIcon = 'heroicon-s-map';
    protected static ?string $navigationLabel = 'Travel Analyzer';
    protected static ?string $title = 'Travel Analyzer';

    protected static string $view = 'filament.pages.travel-analyzer';


    public function mount(): void
    {

        $this->environments = Environment::with('datasets')->get();
        $this->isDataSetHidden = true;

    }


    protected function getForms(): array
    {
        return ['env_form', 'travel_form'];
    }


    public function travel_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Travel Details')
                    ->icon('heroicon-s-map')
                    ->schema([
                        Forms\Components\Fieldset::make('from_details')
                            ->label('From Details')
                            ->schema([
                                TextInput::make('lat_from')
                                    ->prefixIcon('heroicon-s-arrows-up-down')
                                    ->label('Latitude')
                                    ->required()
                                    ->minValue(-90.0)
                                    ->maxValue(90.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('long_from')
                                    ->label('Longitude')
                                    ->prefixIcon('heroicon-s-arrows-right-left')
                                    ->required()
                                    ->minValue(-180.0)
                                    ->maxValue(180.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('address_from')
                                    ->prefixIcon('heroicon-s-map')
//                                    ->helperText('use an address and geocode it')
                                    ->columnSpan(2)
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('geocode_address')
                                            ->icon('heroicon-m-map-pin')
                                            ->action(function (Forms\Get $get, Forms\Set $set) {
                                                if ($get('address_from')) {
                                                    $coords = $this->geocodeAddress($get('address_from'));
                                                    if ($coords['lat'] && $coords['lng']) {
                                                        $set('lat_from', $coords['lat']);
                                                        $set('long_from', $coords['lng']);
                                                        Notification::make('passedgeo')
                                                            ->icon('heroicon-s-map')
                                                            ->title('Successful Geocode')
                                                            ->success()
                                                            ->send();
                                                    } else {
                                                        Notification::make('failedgeo')
                                                            ->title('Failed Geocode')
                                                            ->danger()
                                                            ->send();
                                                    }
                                                } else {
                                                    Notification::make('noaddress')
                                                        ->title('Please enter an address')
                                                        ->warning()
                                                        ->send();
                                                }
                                            }))
                                    ->hint('click the map icon to geocode this!'),
                            ])->columnSpan(1),
                        Forms\Components\Fieldset::make('to_details')
                            ->label('To Details')
                            ->schema([
                                TextInput::make('lat_to')
                                    ->prefixIcon('heroicon-s-arrows-up-down')
                                    ->label('Latitude')
                                    ->required()
                                    ->minValue(-90.0)
                                    ->maxValue(90.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('long_to')
                                    ->label('Longitude')
                                    ->prefixIcon('heroicon-s-arrows-right-left')
                                    ->required()
                                    ->minValue(-180.0)
                                    ->maxValue(180.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('address_to')
                                    ->prefixIcon('heroicon-s-map')
//                                    ->helperText('use an address and geocode it')
                                    ->columnSpan(2)
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('geocode_address')
                                            ->icon('heroicon-m-map-pin')
                                            ->action(function (Forms\Get $get, Forms\Set $set) {
                                                if ($get('address_to')) {
                                                    $coords = $this->geocodeAddress($get('address_to'));
                                                    if ($coords['lat'] && $coords['lng']) {
                                                        $set('lat_to', $coords['lat']);
                                                        $set('long_to', $coords['lng']);
                                                        Notification::make('passedgeo')
                                                            ->icon('heroicon-s-map')
                                                            ->title('Successful Geocode')
                                                            ->success()
                                                            ->send();
                                                    } else {
                                                        Notification::make('failedgeo')
                                                            ->title('Failed Geocode')
                                                            ->danger()
                                                            ->send();
                                                    }
                                                } else {
                                                    Notification::make('noaddress')
                                                        ->title('Please enter an address')
                                                        ->warning()
                                                        ->send();
                                                }
                                            }))
                                    ->hint('click the map icon to geocode this!'),
                            ])->columnSpan(1),

                    ])
                    ->footerActions([
                        Forms\Components\Actions\Action::make('analyze_travel')
                            ->action(function (Forms\Get $get, Forms\Set $set) {
                                $this->dotheThing($get);
                            })
                    ])
                    ->columns(2),
            ])->statePath('data');
    }

    public function dotheThing($get)
    {

        $token = $this->authenticatePSO($this->selectedEnvironment->base_url, $get('account_id'), $get('username'), $get('password'));
//        dd($token);

    }

}
