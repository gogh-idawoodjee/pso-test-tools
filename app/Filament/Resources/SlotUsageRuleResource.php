<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SlotUsageRuleResource\Pages;
use App\Models\SlotUsageRule;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SlotUsageRuleResource extends Resource
{
    protected static ?string $model = SlotUsageRule::class;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|null|\UnitEnum $navigationGroup = 'Base Data';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema(
                SlotUsageRule::getForm()
            );
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
                Tables\Actions\EditAction::make()->slideOver(),
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
            'index' => Pages\ListSlotUsageRules::route('/'),
            //            'create' => Pages\CreateSlotUsageRule::route('/create'),
            //            'edit' => Pages\EditSlotUsageRule::route('/{record}/edit'),
        ];
    }
}
