<?php

namespace App\Filament\Pages\Tools\TrimExport;

use App\Filament\Pages\Tools\TrimExportEntry;
use App\Support\TrimExport\FilterChoices;
use App\Support\TrimExport\TableChoices;
use App\Support\TrimExport\TableDependencies;
use App\Support\TrimExport\TrimPlan;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** The fields of Trim export's Tables and Rows steps. Option keys match TrimPlan::fromOptions(). */
class TrimExportFields
{
    public const string PRESET_CLEANUP = 'cleanup';

    public const string PRESET_CUSTOM = 'custom';

    /** @return list<Component> */
    public static function tables(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 3])->schema([
                Radio::make('options.table_mode')
                    ->label('Ticked tables are')
                    ->options([TrimPlan::MODE_REMOVE => 'Removed', TrimPlan::MODE_KEEP => 'Kept'])
                    ->default(TrimPlan::MODE_REMOVE)
                    ->inline()
                    ->required()
                    ->live()
                    // The preset is a Remove list: in Keep mode the same ticks mean something else.
                    ->afterStateUpdated(static fn (Set $set) => $set('options.preset', self::PRESET_CUSTOM)),
                Select::make('options.preset')
                    ->label('Preset')
                    ->options([self::PRESET_CLEANUP => 'Cleanup for input', self::PRESET_CUSTOM => 'Custom'])
                    ->helperText('Cleanup for input removes PSO output and other tables that do not belong in a file you load or compare as input.')
                    ->default(self::PRESET_CLEANUP)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(static function (?string $state, Set $set, TrimExportEntry $livewire): void {
                        if ($state === self::PRESET_CLEANUP) {
                            $set('options.table_mode', TrimPlan::MODE_REMOVE);
                            $set('options.tables', TableDependencies::cleanupPresent(array_keys($livewire->tableCounts)));
                        }
                    }),
                Toggle::make('options.allow_input_reference_removal')
                    ->label('Allow Input_Reference to be removed')
                    ->helperText('PSO will not load a file without it.')
                    ->default(false)
                    ->live()
                    // A greyed tick the user cannot clear would fail validation: drop it when the lock goes back on.
                    ->afterStateUpdated(static function (?bool $state, Get $get, Set $set): void {
                        if (! $state) {
                            $set('options.tables', array_values(array_diff($get('options.tables') ?? [], [TableDependencies::INPUT_REFERENCE])));
                        }
                    }),
            ]),
            CheckboxList::make('options.tables')
                ->hiddenLabel()
                ->options(static fn (TrimExportEntry $livewire): array => TableChoices::options($livewire->tableCounts))
                ->disableOptionWhen(static fn (string $value, Get $get): bool => $value === TableDependencies::INPUT_REFERENCE && ! $get('options.allow_input_reference_removal'))
                ->searchable()
                ->bulkToggleable()
                ->columns(3)
                ->live()
                ->required(static fn (Get $get): bool => $get('options.table_mode') === TrimPlan::MODE_KEEP)
                ->validationMessages(['required' => 'Tick at least one table to keep.'])
                ->afterStateUpdated(static fn (Set $set) => $set('options.preset', self::PRESET_CUSTOM))
                ->columnSpanFull(),
            Text::make(static fn (Get $get, TrimExportEntry $livewire): string => TableChoices::footer($livewire->tableCounts, self::droppedTables($get, $livewire), read: $livewire->tablesForFileId !== null))
                ->columnSpanFull(),
            Group::make(static fn (Get $get, TrimExportEntry $livewire): array => array_map(
                static fn (string $warning): Text => Text::make($warning)->color('warning'),
                TableDependencies::warnings(array_keys($livewire->tableCounts), self::droppedTables($get, $livewire)),
            ))->columnSpanFull(),
        ];
    }

    /** @return list<string> the tables the current choices remove, by the same rule the run uses */
    private static function droppedTables(Get $get, TrimExportEntry $livewire): array
    {
        return TrimPlan::fromOptions([
            'table_mode' => $get('options.table_mode'),
            'tables' => $get('options.tables') ?? [],
            'allow_input_reference_removal' => $get('options.allow_input_reference_removal'),
        ], 'UTC')->droppedTables(array_map('strval', array_keys($livewire->tableCounts)));
    }

    /** @return list<Component> */
    public static function rows(): array
    {
        $choices = static fn (TrimExportEntry $livewire): FilterChoices => $livewire->filterChoices() ?? new FilterChoices;

        return [
            Text::make('Leave every filter empty to keep all rows: the run then only removes the tables chosen on the Tables step.')
                ->color('gray')
                ->columnSpanFull(),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Select::make('options.region_ids')
                    ->label('Regions')
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->options(static fn (TrimExportEntry $livewire): array => $choices($livewire)->regions)
                    ->helperText('Keeps resources in these regions (Resource_Region) and activities at their locations (Location.region_id or Location_Region).')
                    ->afterStateUpdated(static function (?array $state, Get $get, Set $set, TrimExportEntry $livewire) use ($choices): void {
                        $set('options.resource_ids', self::keepAllowed($get('options.resource_ids'), $choices($livewire)->resourcesIn($state ?? [])));
                    }),
                // Searched, not listed: a LOAD can hold thousands of resources and activities.
                Select::make('options.resource_ids')
                    ->label('Resources')
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->getSearchResultsUsing(static fn (string $search, Get $get, TrimExportEntry $livewire): array => FilterChoices::matching($choices($livewire)->resourcesIn($get('options.region_ids') ?? []), $search))
                    ->getOptionLabelsUsing(static fn (array $values, Get $get, TrimExportEntry $livewire): array => FilterChoices::labelsOf($choices($livewire)->resourcesIn($get('options.region_ids') ?? []), $values))
                    ->helperText('Optional. Type an id or name to search. Narrows the kept resources to these, within the regions above.'),
                Select::make('options.activity_type_ids')
                    ->label('Activity types')
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->options(static fn (TrimExportEntry $livewire): array => $choices($livewire)->activityTypes)
                    ->helperText('Only types that have activities in this file, with how many. Default is activities with no type.')
                    ->afterStateUpdated(static function (?array $state, Get $get, Set $set, TrimExportEntry $livewire) use ($choices): void {
                        $set('options.activity_ids', self::keepAllowed($get('options.activity_ids'), $choices($livewire)->activitiesOf($state ?? [])));
                    }),
                Select::make('options.activity_ids')
                    ->label('Activities')
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->getSearchResultsUsing(static fn (string $search, Get $get, TrimExportEntry $livewire): array => FilterChoices::matching($choices($livewire)->activitiesOf($get('options.activity_type_ids') ?? []), $search))
                    ->getOptionLabelsUsing(static fn (array $values, Get $get, TrimExportEntry $livewire): array => FilterChoices::labelsOf($choices($livewire)->activitiesOf($get('options.activity_type_ids') ?? []), $values))
                    ->helperText('Optional. Type an id to search. Only these activities are kept.'),
                DatePicker::make('options.window_start')
                    ->label('Appointment window from')
                    ->native(false)
                    ->live()
                    ->required(static fn (Get $get): bool => filled($get('options.window_end')))
                    ->helperText('Keeps activities whose APPOINTMENT window overlaps these days, and shifts that overlap them.'),
                DatePicker::make('options.window_end')
                    ->label('Appointment window to')
                    ->native(false)
                    ->live()
                    ->required(static fn (Get $get): bool => filled($get('options.window_start')))
                    ->rule(static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $start = $get('options.window_start');
                        if (filled($value) && filled($start) && substr((string) $value, 0, 10) < substr((string) $start, 0, 10)) {
                            $fail('The window must end on or after the day it starts.');
                        }
                    }),
            ]),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                DateTimePicker::make('options.input_reference_datetime')
                    ->label('Input_Reference datetime override (UTC)')
                    ->native(false)
                    ->seconds(false)
                    ->format('Y-m-d H:i:s')
                    // Shown as stored, with no zone conversion: the value is UTC, as the label says.
                    ->timezone(config('app.timezone'))
                    ->nullable()
                    ->helperText('Optional. Replaces the datetime in the Input_Reference row.'),
                Toggle::make('options.count_only')
                    ->label('Count only')
                    ->helperText('Show the counts and warnings, but write no file.')
                    ->default(false),
            ]),
        ];
    }

    /**
     * The chosen ids still among the allowed options, in their order.
     *
     * @param  array<string, string>  $allowed
     * @return list<mixed>
     */
    private static function keepAllowed(mixed $chosen, array $allowed): array
    {
        return array_values(array_filter(
            is_array($chosen) ? $chosen : [],
            static fn (mixed $id): bool => is_scalar($id) && array_key_exists((string) $id, $allowed),
        ));
    }
}
