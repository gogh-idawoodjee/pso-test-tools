<?php

namespace App\Filament\Resources\EnvironmentResource\RelationManagers;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class DatasetsRelationManager extends RelationManager
{
    protected static string $relationship = 'datasets';

    public string $dse_duration;

    public array $dataset_attributes;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('rota')
                    ->required(),
            ]);
    }

    protected static string|BackedEnum|null $icon = 'heroicon-o-cube-transparent';

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
                CreateAction::make()->slideOver()->label('Add Dataset'),
            ])
            ->recordActions([
                DeleteAction::make(),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
