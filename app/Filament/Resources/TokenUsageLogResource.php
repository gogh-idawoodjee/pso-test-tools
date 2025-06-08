<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TokenUsageLogResource\Pages;

use App\Models\TokenUsageLog;

use App\Traits\AdminViewable;
use Exception;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TokenUsageLogResource extends Resource
{

    use AdminViewable;

    protected static ?string $model = TokenUsageLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Core';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    /**
     * @throws Exception
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('user.name')->label('User')->searchable(),
                TextColumn::make('token.name')->label('Token')->searchable(),
                TextColumn::make('route')->searchable(),
                TextColumn::make('method')->sortable(),
                TextColumn::make('ip_address')->label('IP Address'),
                TextColumn::make('created_at')->label('Logged At')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('method')
                    ->options([
                        'GET' => 'GET',
                        'POST' => 'POST',
                        'PUT' => 'PUT',
                        'DELETE' => 'DELETE',
                    ]),
            ])
            ->actions([
//                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListTokenUsageLogs::route('/'),
//            'create' => Pages\CreateTokenUsageLog::route('/create'),
//            'edit' => Pages\EditTokenUsageLog::route('/{record}/edit'),
        ];
    }
}
