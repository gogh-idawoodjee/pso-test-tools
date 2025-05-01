<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppointmentTemplateResource\Pages;
use App\Filament\Resources\AppointmentTemplateResource\RelationManagers;
use App\Models\AppointmentTemplate;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AppointmentTemplateResource extends Resource
{
    protected static ?string $model = AppointmentTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationGroup = 'Base Data';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(AppointmentTemplate::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
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
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
    

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppointmentTemplates::route('/'),
            'create' => Pages\CreateAppointmentTemplate::route('/create'),
            'edit' => Pages\EditAppointmentTemplate::route('/{record}/edit'),
        ];
    }
}
