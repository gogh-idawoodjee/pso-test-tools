<?php

namespace App\Filament\Resources\EnvironmentResource\RelationManagers;


use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;


class DatasetsRelationManager extends RelationManager
{
    protected static string $relationship = 'datasets';

    public string $dse_duration;
    public array $dataset_attributes;


    public function isReadOnly(): bool
    {
        parent::isReadOnly();
        return false;
    }


    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('rota')
                    ->required(),
            ]);
    }


    public function table(Table $table): Table
    {

        return $table
            ->description('Changes are as saved as you go')
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextInputColumn::make('name')->label('Dataset ID'),
                Tables\Columns\TextInputColumn::make('rota')->label('Rota ID'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),

            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
