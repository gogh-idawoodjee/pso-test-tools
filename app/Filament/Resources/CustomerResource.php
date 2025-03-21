<?php

namespace App\Filament\Resources;

use App\Enums\Status;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Models\Customer;
use App\Models\Environment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;


    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    public static function infolist(Infolist $infolist): Infolist
    {

        return $infolist
            ->name('Customer')
            ->schema([
                TextEntry::make('name'),
                TextEntry::make('address'),
                TextEntry::make('city'),
                TextEntry::make('postcode'),
                TextEntry::make('country'),
                TextEntry::make('status'),
                TextEntry::make('region_id'),

            ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Customer Info')->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->enum(Status::class)
                            ->options(Status::class)
                            ->required(),
                        Forms\Components\Fieldset::make('Location Info')->schema([
                            Forms\Components\TextInput::make('address')
                                ->required(),
                            Forms\Components\TextInput::make('city')
                                ->required(),
                            Forms\Components\TextInput::make('postcode')
                                ->required(),
                            Forms\Components\TextInput::make('country')
                                ->required(),
                            Forms\Components\TextInput::make('lat')
                                ->numeric(),
                            Forms\Components\TextInput::make('long')
                                ->numeric(),
                            Forms\Components\Select::make('region_id')
                                ->searchable()
                                ->relationship('region', 'name')
                                ->required(),
                        ])
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city')
                    ->searchable(),
                Tables\Columns\TextColumn::make('postcode')
                    ->searchable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable(),
                // todo,change from enum to boolean
                Tables\Columns\IconColumn::make('status')->boolean()
                    ->searchable(),
                Tables\Columns\TextColumn::make('lat')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('long')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('region.name')
                    ->numeric()
                    ->sortable(),
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
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
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
            //
            RelationManagers\TasksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
