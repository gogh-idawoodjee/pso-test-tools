<?php

namespace App\Filament\Resources\EnvironmentResource\RelationManagers;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use App\Filament\Resources\DatasetResource;
use App\Models\Dataset;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedCubeTransparent;

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
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(static fn (Dataset $record): string => DatasetResource::getUrl('view', ['record' => $record])),
                DeleteAction::make(),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
