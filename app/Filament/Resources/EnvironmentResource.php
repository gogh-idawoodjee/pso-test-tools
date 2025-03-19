<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EnvironmentResource\Pages;
use App\Filament\Resources\EnvironmentResource\RelationManagers;
use App\Models\Environment;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EnvironmentResource extends Resource
{
    protected static ?string $model = Environment::class;

    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';


    public static function form(Form $form): Form
    {
        return $form
            ->schema(Environment::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('name')
                    ->description(function (Environment $record) {
                        return $record->description;
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('base_url')
                    ->label('Base URL')
                    ->searchable(),
                Tables\Columns\TextColumn::make('account_id')
                    ->label('Account ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('username')
                    ->searchable(),
                Tables\Columns\TextColumn::make('datasets_count')->counts('datasets')
                    ->label('Datasets')
                    ->badge()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make('Manage')->label('Manage'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [

            RelationManagers\DatasetsRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnvironments::route('/'),
            'create' => Pages\CreateEnvironment::route('/create'),
            'edit' => Pages\EditEnvironment::route('/{record}/edit'),
        ];
    }
}
