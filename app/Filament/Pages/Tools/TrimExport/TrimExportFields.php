<?php

namespace App\Filament\Pages\Tools\TrimExport;

use App\Filament\Pages\Tools\TrimExportEntry;
use App\Support\TrimExport\TableChoices;
use App\Support\TrimExport\TableDependencies;
use App\Support\TrimExport\TrimPlan;
use Filament\Forms\Components\CheckboxList;
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
                    ->live(),
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
                            $set('options.tables', TableDependencies::cleanupPresent(array_keys($livewire->tableCounts)));
                        }
                    }),
                Toggle::make('options.allow_input_reference_removal')
                    ->label('Allow Input_Reference to be removed')
                    ->helperText('PSO will not load a file without it.')
                    ->default(false)
                    ->live(),
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
            Text::make(static fn (Get $get, TrimExportEntry $livewire): string => TableChoices::footer($livewire->tableCounts, self::droppedTables($get, $livewire)))
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
}
