<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Models\Scopes\DatasetOwnedModel;
use Filament\Forms;
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

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function taskType(): BelongsTo
    {
        return $this->belongsTo(DatasetReferenceValue::class, 'task_type_id');
    }

    public static function getForm(): array
    {
        return [

            Forms\Components\Select::make('task_type_id')->label('Task Type')->options([])->searchable()->required(), // finished in Task 7
            Forms\Components\Select::make('status')->required()
                ->enum(TaskStatus::class)
                ->options(TaskStatus::class),
            Forms\Components\TextInput::make('duration')->required()->numeric(),
        ];
    }

    #[Override]
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
