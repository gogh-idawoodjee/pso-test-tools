<?php

namespace App\Filament\Resources\DatasetResource\RelationManagers;

use App\Enums\ReferenceEntityType;
use App\Filament\Resources\DatasetReferenceValueResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** One tab per entity type on the Dataset page; subclasses only name the type. */
abstract class ReferenceValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'referenceValues';

    abstract public static function entityType(): ReferenceEntityType;

    /** Filament makes relation managers read-only on a view page; these tabs are where rows get edited. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return static::entityType()->pluralLabel();
    }

    /** Row count of this type, shown on the tab. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->referenceValues()->where('entity_type', static::entityType())->count();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema(DatasetReferenceValueResource::formSchema(static::entityType(), lockDataset: true, datasetId: fn (): string => $this->getOwnerRecord()->id));
    }

    public function table(Table $table): Table
    {
        $type = static::entityType();

        $columns = [
            Tables\Columns\TextColumn::make('pso_id')->label('PSO ID')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('label')->searchable()->sortable(),
        ];

        if ($type === ReferenceEntityType::TaskType) {
            $columns[] = Tables\Columns\TextColumn::make('attributes.priority')->label('Priority');
            $columns[] = Tables\Columns\TextColumn::make('attributes.base_value')->label('Base value');
            $columns[] = Tables\Columns\TextColumn::make('attributes.base_duration')->label('Duration (min)');
        }

        $columns[] = Tables\Columns\TextColumn::make('origin')->badge();
        $columns[] = DatasetReferenceValueResource::statusColumn();

        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('entity_type', $type))
            ->recordTitleAttribute('label')
            ->columns($columns)
            ->defaultSort('label')
            ->headerActions([
                CreateAction::make()
                    ->slideOver()
                    ->mutateDataUsing(static fn (array $data): array => DatasetReferenceValueResource::completeNewRow($data, $type)),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                DatasetReferenceValueResource::resetToPsoAction(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
