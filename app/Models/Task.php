<?php

namespace App\Models;

use App\Enums\ReferenceEntityType;
use App\Enums\TaskStatus;
use App\Models\Scopes\DatasetOwnedModel;
use App\Rules\ReferenceValueInDataset;
use Filament\Forms;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @method static Model|static create(array $attributes = [])
 * @method static Builder|static query()
 *
 * @mixin Builder
 */
class Task extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    protected function casts(): array
    {
        return [
            'appt_window_finish' => 'datetime',
            'appt_window_start' => 'datetime',
            'regions' => 'array',
            'skills' => 'array',
            'booked_at' => 'datetime',
            'status' => TaskStatus::class,
        ];
    }

    protected $touches = ['customer'];

    protected $guarded = [];

    #[Override]
    protected static function booted(): void
    {

        static::addGlobalScope(new DatasetOwnedModel);
        static::creating(static function (self $task): void {
            if (empty($task->dataset_id) && $task->customer_id) {
                $task->dataset_id = Customer::withoutGlobalScopes()->whereKey($task->customer_id)->value('dataset_id');
            }

            $taskType = $task->task_type_id
                ? DatasetReferenceValue::withoutGlobalScopes()->find($task->task_type_id)
                : null;

            if ($taskType) {
                $task->duration ??= $taskType->attribute('base_duration');
                $task->base_value ??= $taskType->attribute('base_value');
            }

            if (empty($task->regions) && $task->customer_id) {
                $regionId = Customer::withoutGlobalScopes()->whereKey($task->customer_id)->value('region_id');
                $regionPsoId = $regionId ? DatasetReferenceValue::withoutGlobalScopes()->whereKey($regionId)->value('pso_id') : null;

                if ($regionPsoId) {
                    $task->regions = [$regionPsoId];
                }
            }

            if (empty($task->friendly_id)) {
                $prefix = strtoupper(substr($taskType?->label ?: 'X', 0, 1));
                do {
                    $friendlyId = 'T-'.$prefix.'_'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                } while (self::withoutGlobalScopes()->where('dataset_id', $task->dataset_id)->where('friendly_id', $friendlyId)->exists());
                $task->friendly_id = $friendlyId;
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isBooked(): bool
    {
        return filled($this->appointment_request_id);
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function taskType(): BelongsTo
    {
        return $this->belongsTo(DatasetReferenceValue::class, 'task_type_id');
    }

    /**
     * @param  string|null  $datasetId  known up front on a customer's relation manager; otherwise taken from the chosen customer
     */
    public static function getForm(?string $datasetId = null): array
    {
        $resolveDataset = static fn (Get $get, ?Task $record): ?string => $datasetId
            ?? $record?->dataset_id
            ?? ($get('customer_id') ? Customer::query()->whereKey($get('customer_id'))->value('dataset_id') : null);

        return [
            Forms\Components\Select::make('task_type_id')
                ->label('Task Type')
                ->required()
                ->searchable()
                ->options(static fn (Get $get, ?Task $record): array => DatasetReferenceValue::optionsFor($resolveDataset($get, $record), ReferenceEntityType::TaskType))
                ->rules([static fn (Get $get, ?Task $record) => new ReferenceValueInDataset($resolveDataset($get, $record), ReferenceEntityType::TaskType)]),
            Forms\Components\Select::make('regions')
                ->label('Regions')
                ->multiple()
                ->searchable()
                ->options(static fn (Get $get, ?Task $record): array => DatasetReferenceValue::psoIdOptionsFor($resolveDataset($get, $record), ReferenceEntityType::Region))
                ->helperText('Blank uses the customer region.'),
            Forms\Components\Select::make('skills')
                ->label('Skills')
                ->multiple()
                ->searchable()
                ->options(static fn (Get $get, ?Task $record): array => DatasetReferenceValue::psoIdOptionsFor($resolveDataset($get, $record), ReferenceEntityType::Skill)),
            Forms\Components\TextInput::make('duration')->label('Duration (minutes)')->numeric()->helperText('Blank uses the task type base duration.'),
        ];
    }

    #[Override]
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
