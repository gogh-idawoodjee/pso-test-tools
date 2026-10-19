<?php

namespace App\Filament\Resources\DatasetResource\RelationManagers;

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\DatasetReferenceValueResource;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One "Reference values" tab on the Dataset page for every entity type (regions, skills, task types, slot
 * usage rule sets, appointment templates, SLA types), with the type as an always-set switch above the
 * table. Replaces six near-identical tabs (2026-10-11). The create form and the task type columns follow
 * the selected type.
 */
class ReferenceValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'referenceValues';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedTableCells;

    public const FILTER = 'entity_type';

    /** Filament makes relation managers read-only on a view page; this tab is where rows get edited. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Reference values';
    }

    /** Per-type counts live on the type switch, so the tab itself carries no number. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return null;
    }

    /**
     * The switch's options: "Regions (312)". One grouped query; types with no rows read "(0)".
     *
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        $counts = $this->getOwnerRecord()->referenceValues()
            ->selectRaw('entity_type, count(*) as n')
            ->groupBy('entity_type')
            ->pluck('n', 'entity_type');

        return collect(ReferenceEntityType::cases())
            ->mapWithKeys(fn (ReferenceEntityType $type): array => [$type->value => $type->pluralLabel().' ('.(int) ($counts[$type->value] ?? 0).')'])
            ->all();
    }

    /** The type the switch is on; Regions until the user picks another. */
    public function currentType(): ReferenceEntityType
    {
        $value = $this->getTableFilterState(self::FILTER)['value'] ?? null;

        return ReferenceEntityType::tryFrom((string) $value) ?? ReferenceEntityType::Region;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema(DatasetReferenceValueResource::formSchema($this->currentType(), lockDataset: true, datasetId: fn (): string => $this->getOwnerRecord()->id));
    }

    public function table(Table $table): Table
    {
        $isTaskType = fn (): bool => $this->currentType() === ReferenceEntityType::TaskType;
        $isTemplate = fn (): bool => $this->currentType() === ReferenceEntityType::AppointmentTemplate;

        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('pso_id')->label('PSO ID')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('label')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('attributes.priority')->label('Priority')->visible($isTaskType),
                Tables\Columns\TextColumn::make('attributes.base_value')->label('Base value')->visible($isTaskType),
                Tables\Columns\TextColumn::make('attributes.base_duration')->label('Duration (min)')->visible($isTaskType),
                Tables\Columns\TextColumn::make('attributes.booking_sla_type_id')->label('Booking SLA type')->placeholder('Dataset default')->visible($isTaskType),
                DatasetReferenceValueResource::blockingColumn()->visible($isTemplate),
                Tables\Columns\TextColumn::make('origin')->badge(),
                DatasetReferenceValueResource::statusColumn(),
            ])
            ->defaultSort('label')
            ->filters([
                SelectFilter::make(self::FILTER)
                    ->label('Type')
                    ->options(fn (): array => $this->typeOptions())
                    ->default(ReferenceEntityType::Region->value)
                    ->selectablePlaceholder(false)
                    ->native(false),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->persistFiltersInSession()
            ->headerActions([
                CreateAction::make()
                    ->label(fn (): string => 'New '.strtolower($this->currentType()->getLabel()))
                    ->slideOver()
                    ->mutateDataUsing(fn (array $data): array => DatasetReferenceValueResource::completeNewRow($data, $this->currentType())),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                DatasetReferenceValueResource::resetToPsoAction(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
