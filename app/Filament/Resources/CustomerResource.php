<?php

namespace App\Filament\Resources;

use App\Enums\TaskStatus;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers\TasksRelationManager;
use App\Models\Customer;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-user-group';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema(
                Customer::getForm()
            );
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
                Tables\Columns\TextColumn::make('country')
                    ->searchable(),

                Tables\Columns\TextColumn::make('region.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tasks_count')
                    ->label('Tasks')
                    ->counts('tasks')
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
            ->recordActions([
                EditAction::make()->slideOver(),
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
            TasksRelationManager::class,
        ];
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Section::make('Customer Information')
                    ->schema([
                        // Left column: Name + Status
                        TextEntry::make('name'),

                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('created_at')
                            ->label('Created At')
                            ->icon('heroicon-o-calendar')
                            ->formatStateUsing(static fn ($state) => $state?->toFormattedDateString() ?? '—'),

                        TextEntry::make('updated_at')
                            ->label('Last Updated')
                            ->icon('heroicon-o-clock')
                            ->tooltip(static fn ($state) => $state?->toDayDateTimeString())
                            ->formatStateUsing(static fn ($state) => $state?->diffForHumans() ?? '—'),
                        ViewEntry::make('summary_last_30')
                            ->view('filament.components.customer-summary-tile')
                            ->viewData([
                                'label' => 'Tasks in the Last 30 Days',
                                'value' => fn ($record) => $record->tasks()
                                    ->where('appt_window_finish', '>=', now()->subDays(30))
                                    ->count(),
                                'icon' => 'heroicon-o-calendar',
                            ]),

                        ViewEntry::make('summary_upcoming')
                            ->view('filament.components.customer-summary-tile')
                            ->viewData([
                                'label' => 'Upcoming Tasks',
                                'value' => fn ($record) => $record->tasks()
                                    ->where('appt_window_finish', '>', now())
                                    ->count(),
                                'icon' => 'heroicon-o-arrow-up',
                            ]),

                        ViewEntry::make('summary_incomplete')
                            ->view('filament.components.customer-summary-tile')
                            ->viewData([
                                'label' => 'Incomplete Tasks',
                                'value' => fn ($record) => $record->tasks()
                                    ->whereNotIn('status', collect(TaskStatus::endStateStatuses())->pluck('value'))
                                    ->count(),
                                'icon' => 'heroicon-o-exclamation-circle',
                            ]),

                    ])
                    ->columns()->columnSpan(1),
                Section::make('Location')
                    ->icon('heroicon-o-map')
                    ->schema([           // Right column: Address + Map
                        TextEntry::make('address')
                            ->label('Address')
                            ->columnSpan(1),

                        TextEntry::make('city')
                            ->label('City')
                            ->columnSpan(1),

                        TextEntry::make('country')
                            ->label('Country')
                            ->columnSpan(1),
                        TextEntry::make('coordinates')
                            ->label('Coordinates')
                            ->state(static function ($record) {
                                return isset($record->lat, $record->long)
                                    ? number_format($record->lat, 5).', '.number_format($record->long, 5)
                                    : '—';
                            }),

                        ViewEntry::make('map')
                            ->view('filament.components.customer-map')
                            ->columnSpanFull(), ])
                    ->columns()->columnSpan(1),
                // Splits the section into 2 columns (left = name/status, right = addr/map)
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
        ];
    }
}
