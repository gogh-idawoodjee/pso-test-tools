<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Filament\Forms;
use Override;

class Task extends Model
{
    use HasFactory, HasUuids;

    protected $casts = [
        'appt_window_finish' => 'datetime',
        'appt_window_start' => 'datetime',
    ];

    protected $touches = ['customer'];
    protected $guarded = [];

    #[Override] protected static function booted(): void
    {
        static::creating(static function (self $task) {

            if ($task->taskType) {
                $task->duration ??= $task->taskType->base_duration;
                $task->base_value ??= $task->taskType->base_value;
            }


            if (empty($task->friendly_id)) {
                // Try to get task type prefix
                $prefix = 'X';
                if (!empty($task->task_type_id)) {
                    $taskType = TaskType::find($task->task_type_id);
                    $prefix = strtoupper(substr($taskType?->name ?? 'X', 0, 1));
                }

                // Generate unique friendly ID
                do {
                    $friendlyId = 'T-' . $prefix . '_' . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                } while (self::where('friendly_id', $friendlyId)->exists());

                $task->friendly_id = $friendlyId;
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function taskType(): BelongsTo
    {
        return $this->belongsTo(TaskType::class);
    }

    public static function getForm(): array
    {
        return [

            Forms\Components\Select::make('task_type_id')->required()
                ->relationship('taskType', 'name'),
            Forms\Components\Select::make('status')->required()
                ->enum(TaskStatus::class)
                ->options(TaskStatus::class),
            Forms\Components\TextInput::make('duration')->required()->numeric(),
        ];
    }
}
