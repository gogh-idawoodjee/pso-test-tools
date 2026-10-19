<?php

namespace App\Filament\Resources;

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Filament\Navigation\Groups;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * Shared behaviour of the six "Base Data" resources. Each subclass names its
 * entity type; everything else lives here.
 */
abstract class DatasetReferenceValueResource extends Resource
{
    protected static ?string $model = DatasetReferenceValue::class;

    protected static string|UnitEnum|null $navigationGroup = Groups::SETUP;

    abstract public static function entityType(): ReferenceEntityType;

    public static function getSlug(?Panel $panel = null): string
    {
        return static::entityType()->slug();
    }

    public static function getModelLabel(): string
    {
        return static::entityType()->getLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::entityType()->pluralLabel();
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return static::entityType()->navigationIcon();
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('entity_type', static::entityType());
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema(static::formSchema(static::entityType()));
    }

    /**
     * The create/edit form for one entity type. With $lockDataset the dataset
     * select is omitted and $datasetId (a closure returning the dataset uuid)
     * tells the uniqueness rule which dataset the row will land in.
     */
    public static function formSchema(ReferenceEntityType $type, bool $lockDataset = false, ?Closure $datasetId = null): array
    {
        $fields = [];

        if (! $lockDataset) {
            $fields[] = Forms\Components\Select::make('dataset_id')
                ->label('Dataset')
                ->options(static fn (): array => Dataset::ownedByCurrentUser()->with('environment')->get()
                    ->mapWithKeys(static fn (Dataset $dataset): array => [$dataset->id => "{$dataset->environment->name} / {$dataset->name}"])
                    ->all())
                ->required()
                ->searchable()
                ->disabledOn('edit')
                ->dehydrated();
        }

        $fields[] = Forms\Components\TextInput::make('pso_id')
            ->label('PSO ID')
            ->helperText('The identifier PSO expects in a request.')
            ->required()
            ->maxLength(32)
            ->rules([
                static fn (Get $get, ?DatasetReferenceValue $record) => Rule::unique('dataset_reference_values', 'pso_id')
                    ->where('dataset_id', $record?->dataset_id ?? ($datasetId ? $datasetId() : $get('dataset_id')))
                    ->where('entity_type', $type->value)
                    ->ignore($record?->id),
            ]);

        $fields[] = Forms\Components\TextInput::make('label')
            ->label('Label')
            ->helperText('Display name. Defaults to the PSO ID when left blank.')
            ->maxLength(255);

        if ($type === ReferenceEntityType::TaskType) {
            $fields[] = Forms\Components\TextInput::make('attributes.priority')->label('Priority')->numeric()->integer()->minValue(1)->maxValue(10);
            $fields[] = Forms\Components\TextInput::make('attributes.base_value')->label('Base value')->numeric()->minValue(0);
            $fields[] = Forms\Components\TextInput::make('attributes.base_duration')->label('Base duration (minutes)')->numeric()->integer()->minValue(1);
            $fields[] = Forms\Components\Select::make('attributes.booking_sla_type_id')
                ->label('Booking SLA type')
                ->options(static fn (Get $get, ?DatasetReferenceValue $record): array => DatasetReferenceValue::psoIdOptionsFor(
                    $record?->dataset_id ?? ($datasetId ? $datasetId() : $get('dataset_id')),
                    ReferenceEntityType::SlaType,
                ))
                ->searchable()
                ->nullable()
                ->helperText('Blank uses the dataset default. Kept here only; a PSO sync never changes it.');
        }

        if ($type === ReferenceEntityType::AppointmentTemplate) {
            // Edits attributes.blocking_override, shown with the effective value. Kept
            // outside the attributes state path and out of the form data, because a
            // partial attributes array would replace PSO's blocking on save; it is
            // merged into the stored attributes after save instead.
            $fields[] = Forms\Components\Toggle::make('blocking_override')
                ->label('Blocking')
                ->default(true)
                // Toggle turns a null state into false on hydration, so read the record instead.
                ->afterStateHydrated(static fn (Forms\Components\Toggle $component, ?DatasetReferenceValue $record) => $component->state($record?->blockingDefault() ?? true))
                ->dehydrated(false)
                ->saveRelationshipsUsing(static fn (DatasetReferenceValue $record, ?bool $state) => $record->setBlockingOverride($state))
                ->helperText('From PSO when synced; change it here if PSO is wrong. Your setting is kept when PSO syncs.');
        }

        return $fields;
    }

    /** Fills in what a create form does not ask for. Shared with the relation managers. */
    public static function completeNewRow(array $data, ReferenceEntityType $type): array
    {
        $data['entity_type'] = $type;
        $data['origin'] = ReferenceValueOrigin::Manual;
        $data['label'] = filled($data['label'] ?? null) ? $data['label'] : $data['pso_id'];

        return $data;
    }

    public static function table(Table $table): Table
    {
        $type = static::entityType();

        $columns = [
            Tables\Columns\TextColumn::make('dataset.name')->label('Dataset')->sortable()->searchable(),
            Tables\Columns\TextColumn::make('pso_id')->label('PSO ID')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('label')->searchable()->sortable(),
        ];

        if ($type === ReferenceEntityType::TaskType) {
            $columns[] = Tables\Columns\TextColumn::make('attributes.priority')->label('Priority');
            $columns[] = Tables\Columns\TextColumn::make('attributes.base_value')->label('Base value');
            $columns[] = Tables\Columns\TextColumn::make('attributes.base_duration')->label('Duration (min)');
            $columns[] = Tables\Columns\TextColumn::make('attributes.booking_sla_type_id')->label('Booking SLA type')->placeholder('Dataset default');
        }

        if ($type === ReferenceEntityType::AppointmentTemplate) {
            $columns[] = static::blockingColumn();
        }

        $columns[] = Tables\Columns\TextColumn::make('origin')->badge();
        $columns[] = static::statusColumn();
        $columns[] = Tables\Columns\TextColumn::make('last_seen_at')->dateTime()->sortable()->toggleable();

        return $table
            ->columns($columns)
            ->defaultSort('label')
            ->filters([
                SelectFilter::make('dataset_id')
                    ->label('Dataset')
                    ->options(static fn (): array => Dataset::ownedByCurrentUser()->pluck('name', 'id')->all()),
                SelectFilter::make('origin')->options(ReferenceValueOrigin::class),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                static::resetToPsoAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    /** Appointment templates: the effective Blocking value, marked when it is a manual override. Shared with the relation managers. */
    public static function blockingColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('blocking')
            ->label('Blocking')
            ->state(static fn (DatasetReferenceValue $record): string => ($record->blockingDefault() ? 'Yes' : 'No').($record->hasBlockingOverride() ? ' (manual)' : ''));
    }

    /** "Missing from PSO" / "Edited" badge, shared with the relation managers. */
    public static function statusColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('status')
            ->label('')
            ->badge()
            ->state(static fn (DatasetReferenceValue $record): ?string => match (true) {
                $record->isMissingFromPso() => 'Missing from PSO',
                $record->is_locally_modified => 'Edited',
                default => null,
            })
            ->color(static fn (?string $state): string => $state === 'Missing from PSO' ? 'danger' : 'warning');
    }

    public static function resetToPsoAction(): Action
    {
        return Action::make('resetToPso')
            ->label('Reset to PSO')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(static fn (DatasetReferenceValue $record): bool => $record->origin === ReferenceValueOrigin::Pso && ($record->is_locally_modified || $record->hasBlockingOverride()))
            ->requiresConfirmation()
            ->modalDescription('The next sync will overwrite this row with the values from PSO. A manual Blocking setting is cleared too.')
            ->action(static function (DatasetReferenceValue $record): void {
                $attributes = $record->getAttribute('attributes');
                if (is_array($attributes) && array_key_exists('blocking_override', $attributes)) {
                    unset($attributes['blocking_override']);
                }

                $record->forceFill(['is_locally_modified' => false, 'attributes' => $attributes])->saveQuietly();
            });
    }
}
