<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TokenUsageLogResource\Pages;
use App\Models\TokenUsageLog;
use App\Traits\AdminViewable;
use BackedEnum;
use Exception;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TokenUsageLogResource extends Resource
{
    use AdminViewable;

    protected static ?string $model = TokenUsageLog::class;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|null|UnitEnum $navigationGroup = 'Core';

    public static function form(Schema $form): Schema
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
            ->recordActions([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTokenUsageLogs::route('/'),
        ];
    }
}
