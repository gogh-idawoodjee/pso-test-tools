<?php

namespace App\Filament\Resources\EnvironmentResource\RelationManagers;

use App\Models\Dataset;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DatasetsRelationManager extends RelationManager
{
    protected static string $relationship = 'datasets';

    public function isReadOnly(): bool
    {
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
            ->description('Changed as saved as you go')
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextInputColumn::make('name'),
                Tables\Columns\TextInputColumn::make('rota'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
//                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('psoload')
                    ->action(function (Dataset $record) {
                        $record->psoload();
                    })
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
