<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EnvironmentResource\Pages;
use App\Filament\Resources\EnvironmentResource\RelationManagers;
use App\Models\Environment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class EnvironmentResource extends Resource
{
    protected static ?string $model = Environment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static string|BackedEnum|null $activeNavigationIcon = 'heroicon-s-circle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Base Data';

    protected static ?int $navigationSort = 1;

    protected static string|Htmlable|null $navigationBadgeTooltip = 'The number of configured environments';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema(Environment::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('name')
                    ->description(static function (Environment $record) {
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
            ->recordActions([
                EditAction::make('Manage')->label('Manage'),
                Action::make('Tools')->label('Tools')
                    ->url(static function (Environment $record) {
                        return self::getUrl('environmentTools', compact('record'));
                    })
                    ->icon('heroicon-o-wrench-screwdriver'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [

            RelationManagers\DatasetsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnvironments::route('/'),
            'create' => Pages\CreateEnvironment::route('/create'),
            'edit' => Pages\EditEnvironment::route('/{record}/edit'),
            'environmentTools' => Pages\EnvironmentTools::route('/environmentTools/{record}'),
        ];
    }
}
