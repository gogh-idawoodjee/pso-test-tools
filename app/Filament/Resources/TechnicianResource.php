<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TechnicianResource\Pages;
use App\Models\Technician;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Support\Enums\FontWeight;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Colors\Color;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Actions;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\ViewEntry;

class TechnicianResource extends Resource
{
    protected static ?string $model = Technician::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Form fields would go here - omitted for brevity
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('personal.full_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('resource_id')
                    ->label('ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('resource_type.description')
                    ->label('Type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('additional_attributes.Truck ID')
                    ->label('Truck ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('location.pso.start.city')
                    ->label('City')
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('skills_count')
                    ->label('Skills')
                    ->counts('skills')
                    ->color('success'),
            ])
            ->filters([
                // Filters would go here
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Technician Overview')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Group::make([
                                    TextEntry::make('personal.full_name')
                                        ->label('Name')
                                        ->weight(FontWeight::Bold)
                                        ->size(TextEntry\TextEntrySize::Large),
                                    TextEntry::make('resource_id')
                                        ->label('Resource ID'),
                                    TextEntry::make('resource_type.description')
                                        ->label('Type')
                                        ->badge()
                                        ->color('primary'),
                                ])->columnSpan(1),

                                Group::make([
                                    TextEntry::make('additional_attributes.Person ID')
                                        ->label('Person ID'),
                                    TextEntry::make('additional_attributes.Home Region')
                                        ->label('Home Region'),
                                    TextEntry::make('additional_attributes.Truck ID')
                                        ->label('Truck ID')
                                        ->badge()
                                        ->color('warning'),
                                ])->columnSpan(1),

                                Group::make([
                                    ViewEntry::make('location_map')
                                        ->label('Location')
                                        ->view('filament.infolists.components.location-map'),
                                ])->columnSpan(1),
                            ]),
                    ])->collapsible(),

                Tabs::make('Resource Details')
                    ->tabs([
                        Tabs\Tab::make('Location')
                            ->schema([
                                Section::make('Address Information')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Group::make([
                                                    TextEntry::make('location.pso.start.name')
                                                        ->label('Location Name'),
                                                    TextEntry::make('location.pso.start.address_line1')
                                                        ->label('Address'),
                                                    TextEntry::make('location.pso.start.city')
                                                        ->label('City'),
                                                    TextEntry::make('location.pso.start.province')
                                                        ->label('Province'),
                                                    TextEntry::make('location.pso.start.postal_code')
                                                        ->label('Postal Code'),
                                                ])->columnSpan(1),

                                                ViewEntry::make('detailed_map')
                                                    ->label('Map')
                                                    ->view('filament.infolists.components.detailed-map')
                                                    ->columnSpan(1),
                                            ]),
                                    ]),
                            ]),

                        Tabs\Tab::make('Regions & Skills')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Section::make('Regions')
                                            ->schema([
                                                ViewEntry::make('regions_table')
                                                    ->view('filament.infolists.components.regions-table'),
                                            ])
                                            ->columnSpan(1),

                                        Section::make('Skills')
                                            ->schema([
                                                ViewEntry::make('skills_table')
                                                    ->view('filament.infolists.components.skills-table'),
                                            ])
                                            ->columnSpan(1),
                                    ]),
                            ]),

                        Tabs\Tab::make('Shifts')
                            ->schema([
                                Section::make('Upcoming Shifts')
                                    ->description('Scheduled shifts for the technician')
                                    ->schema([
                                        ViewEntry::make('calendar_view')
                                            ->view('filament.infolists.components.shifts-calendar'),

                                        ViewEntry::make('shifts_table')
                                            ->view('filament.infolists.components.shifts-table'),
                                    ]),
                            ]),

                        Tabs\Tab::make('Statistics')
                            ->schema([
                                Section::make('Utilization Stats')
                                    ->schema([
                                        ViewEntry::make('utilization_chart')
                                            ->view('filament.infolists.components.utilization-chart'),
                                    ]),
                            ]),
                    ])
                    ->activeTab(1)
                    ->columnSpan('full'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Relations would go here
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTechnicians::route('/'),
            'create' => Pages\CreateTechnician::route('/create'),
            'edit' => Pages\EditTechnician::route('/{record}/edit'),
//            'view' => Pages\ViewTechnician::route('/{record}'),
        ];
    }
}
