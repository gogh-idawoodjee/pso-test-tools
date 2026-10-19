<?php

namespace App\Filament\Resources;

use App\Enums\ReferenceEntityType;
use App\Filament\Navigation\Groups;
use App\Filament\Resources\DatasetResource\Pages;
use App\Filament\Resources\DatasetResource\RelationManagers;
use App\Models\Dataset;
use App\Support\DatasetSettings;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DatasetResource extends Resource
{
    protected static ?string $model = Dataset::class;

    protected static string|UnitEnum|null $navigationGroup = Groups::SETUP;

    protected static ?int $navigationSort = -1;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ownedByCurrentUser();
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema(Dataset::getForm());
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dataset')->schema([
                TextEntry::make('environment.name')->label('Environment'),
                TextEntry::make('name')->label('Dataset ID'),
                TextEntry::make('rota')->label('Rota ID'),
            ])->columns(3),
            Section::make('Last sync from PSO')
                ->description('Regions, skills, task types, slot usage rule sets, appointment templates and SLA types, pulled from the PSO gateway.')
                ->extraAttributes(static fn ($livewire): array => method_exists($livewire, 'isSyncRunning') && $livewire->isSyncRunning()
                    ? ['wire:poll.5s' => 'refreshRecord']
                    : [])
                ->schema([
                    View::make('filament.components.sync-in-progress')
                        ->columnSpanFull()
                        ->visible(static fn ($livewire): bool => method_exists($livewire, 'isSyncRunning') && $livewire->isSyncRunning()),
                    TextEntry::make('last_sync_summary.status')
                        ->label('Result')
                        ->badge()
                        ->default('never')
                        ->formatStateUsing(static fn (?string $state): string => match ($state) {
                            'succeeded' => 'Succeeded',
                            'failed' => 'Failed',
                            default => 'Never synced',
                        })
                        ->color(static fn (?string $state): string => match ($state) {
                            'succeeded' => 'success',
                            'failed' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('last_synced_at')->label('Last successful sync')->dateTime()->placeholder('Never'),
                    TextEntry::make('last_sync_summary.error')
                        ->label('Error')
                        ->color('danger')
                        ->visible(static fn ($state): bool => filled($state))
                        ->columnSpanFull(),
                    TextEntry::make('last_sync_summary.counts')
                        ->label('Counts')
                        ->columnSpanFull()
                        ->visible(static fn ($state, $livewire): bool => filled($state) && ($livewire->showSyncCounts ?? false))
                        ->listWithLineBreaks()
                        ->state(static fn (Dataset $record): array => static::countLines($record)),
                ])->columns(2),
            Section::make('Settings')
                ->description('What the tools read for this dataset. Use Edit settings above to change them. Grey means the default.')
                ->collapsible()
                ->columnSpanFull()
                ->columns(3)
                ->schema(static::settingsEntries()),
        ]);
    }

    /** Read-only view of the settings the form edits, grouped by tool, as pills: coloured when set, grey for the default. */
    public static function settingsEntries(): array
    {
        $pill = static fn (string $name, string $label, string $blank, callable $pick): TextEntry => TextEntry::make($name)
            ->label($label)
            ->badge()
            ->state(static fn (Dataset $record): string => (string) ($pick($record->settings) ?: $blank))
            ->color(static fn (Dataset $record): string => $pick($record->settings) ? 'primary' : 'gray');
        $pills = static fn (string $name, string $label, callable $pick): TextEntry => TextEntry::make($name)
            ->label($label)
            ->badge()
            ->state(static function (Dataset $record) use ($pick): array {
                $lines = [];
                foreach ((array) $pick($record->settings) as $key => $value) {
                    $lines[] = is_int($key) ? (string) $value : "{$key}: {$value}";
                }

                return $lines === [] ? ['None'] : $lines;
            })
            ->color(static fn (Dataset $record): string => (array) $pick($record->settings) === [] ? 'gray' : 'info');

        return [
            Fieldset::make('Names and time zones')->columns(1)->schema([
                $pill('display_name', 'Customer name on reports', 'Environment name', static fn (DatasetSettings $s) => $s->displayName),
                $pill('dataset_display_name', 'Dataset name on reports', 'Dataset ID', static fn (DatasetSettings $s) => $s->datasetDisplayName),
                $pill('time_zone', 'Time zone', 'UTC', static fn (DatasetSettings $s) => $s->timeZone),
                $pill('ifs_time_zone', 'IFS event log time zone', 'Same as time zone', static fn (DatasetSettings $s) => $s->ifsTimeZone),
                $pill('region_label', 'Region label (travel tuning)', 'Region', static fn (DatasetSettings $s) => $s->regionLabel),
            ]),
            Fieldset::make('Region availability')->columns(1)->schema([
                $pill('region_pivot', 'Region colours', 'Red below 1, amber below 3', static fn (DatasetSettings $s) => ($s->regionPivot['red_below'] ?? 1) === 1 && ($s->regionPivot['amber_below'] ?? 3) === 3
                    ? null
                    : sprintf('Red below %d, amber below %d', $s->regionPivot['red_below'] ?? 1, $s->regionPivot['amber_below'] ?? 3)),
                $pill('supervisor_label', 'Supervisor label', 'Supervisor', static fn (DatasetSettings $s) => $s->supervisorLabel),
                $pills('exclude_resource_types', 'Resource types left out', static fn (DatasetSettings $s) => $s->excludeResourceTypes),
                $pills('resource_type_names', 'Resource type names', static fn (DatasetSettings $s) => $s->resourceTypeNames),
                $pills('region_labels', 'Region names', static fn (DatasetSettings $s) => $s->regionLabels),
                $pills('attribute_labels', 'Attribute labels', static fn (DatasetSettings $s) => $s->attributeLabels),
            ]),
            Fieldset::make('Appointment booking')->columns(1)->schema([
                $pill('appointment_sla_type_id', 'SLA type for appointment offers', 'Not set', static fn (DatasetSettings $s) => $s->appointmentSlaTypeId),
                $pill('default_booking_sla_type_id', 'Default SLA type for booking', 'Not set', static fn (DatasetSettings $s) => $s->defaultBookingSlaTypeId),
            ]),
        ];
    }

    /** One line per entity type: "Regions: 3 created, 0 updated, 1 kept (edited), 2 missing". */
    public static function countLines(Dataset $record): array
    {
        $lines = [];
        foreach ((array) ($record->last_sync_summary['counts'] ?? []) as $type => $counts) {
            $label = ReferenceEntityType::tryFrom($type)?->pluralLabel() ?? $type;
            $lines[] = sprintf('%s: %d created, %d updated, %d kept (edited), %d missing',
                $label, $counts['created'] ?? 0, $counts['updated'] ?? 0, $counts['kept'] ?? 0, $counts['missing'] ?? 0);
        }

        return $lines;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('environment.name')
                    ->label('Environment')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Dataset ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rota')
                    ->label('Rota ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Last synced')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
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
            RelationManagers\ReferenceValuesRelationManager::class,
            RelationManagers\CustomersRelationManager::class,
            RelationManagers\ExportFilesRelationManager::class,
            'toolRuns' => RelationManagers\ToolRunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDatasets::route('/'),
            'view' => Pages\ViewDataset::route('/{record}'),
        ];
    }
}
