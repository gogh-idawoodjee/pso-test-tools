<?php

namespace App\Traits;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Illuminate\Support\Collection;

trait FormTrait
{

    public Collection $environments;
    public ?array $environment_data = [];

    public function env_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Environment')
                    ->icon('heroicon-s-circle-stack')
                    ->schema([
                        Toggle::make('send_to_pso')->inline(false),
                        Select::make('Environment')
                            ->options($this->environments->pluck('name', 'id'))
                            ->requiredIf('send_to_pso', true),
                        Select::make('Dataset')
                    ])->columns(3),

            ])->statePath('environment_data');

    }

}
