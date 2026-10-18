<?php

namespace App\Models;

use App\Enums\Tool;
use App\Enums\ToolRunStatus;
use App\Models\Scopes\ScopeOwnedModel;
use App\Tools\Contracts\KeepsInputsPrivate;
use App\Tools\Contracts\Tool as ToolImplementation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;

/**
 * One run of a tool against a set of export files. The result is the tool's data
 * file, encrypted at rest, and outlives the raw exports it was built from.
 *
 * @mixin Builder
 */
class ToolRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'scope_type', 'scope_id', 'tool', 'run_date', 'inputs', 'private_inputs', 'started_by_user_id', 'status',
        'result', 'warnings', 'progress', 'summary', 'error_message', 'queued_at', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'tool' => Tool::class,
            'status' => ToolRunStatus::class,
            'inputs' => 'array',
            'private_inputs' => 'encrypted:array',
            'warnings' => 'array',
            'progress' => 'array',
            'summary' => 'array',
            'result' => 'encrypted:array',
            'run_date' => 'date',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    #[Override]
    protected static function booted(): void
    {
        static::addGlobalScope(new ScopeOwnedModel);

        static::creating(static function (self $run): void {
            $run->status ??= ToolRunStatus::Queued;
            $run->queued_at ??= now();
        });
    }

    /** Every column except `result` (1 to 2 MB a row) and `private_inputs`, both encrypted; lists and polls must not load or decrypt them. */
    public function scopeWithoutResult(Builder $query): void
    {
        $query->select([
            'id', 'scope_type', 'scope_id', 'tool', 'run_date', 'inputs', 'started_by_user_id', 'status', 'progress',
            'warnings', 'summary', 'error_message', 'queued_at', 'started_at', 'finished_at', 'created_at', 'updated_at',
        ]);
    }

    /**
     * The inputs and private_inputs attributes of a new run. For a tool that keeps inputs private (KeepsInputsPrivate)
     * the option keys and source run keys it names move into private_inputs, which is encrypted; every other tool's
     * inputs are stored as they are, with private_inputs null.
     *
     * @param  array<string, mixed>  $inputs  files, options and, for a tool that takes runs, runs
     * @return array{inputs: array<string, mixed>, private_inputs: array<string, mixed>|null}
     */
    public static function inputAttributes(ToolImplementation $tool, array $inputs): array
    {
        if (! $tool instanceof KeepsInputsPrivate) {
            return ['inputs' => $inputs, 'private_inputs' => null];
        }

        $optionKeys = array_flip($tool->privateOptionKeys());
        $options = (array) ($inputs['options'] ?? []);
        $private = ['options' => array_intersect_key($options, $optionKeys)];
        $inputs['options'] = array_diff_key($options, $optionKeys);
        if (isset($inputs['runs'])) {
            $runKeys = array_flip($tool->privateRunKeys());
            $rows = array_values(array_filter((array) $inputs['runs'], 'is_array'));
            $private['runs'] = array_map(static fn (array $row): array => array_intersect_key($row, $runKeys), $rows);
            $inputs['runs'] = array_map(static fn (array $row): array => array_diff_key($row, $runKeys), $rows);
        }

        return ['inputs' => $inputs, 'private_inputs' => $private];
    }

    /**
     * The run's options: the plain ones in inputs, with the typed free text kept encrypted in private_inputs on top.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [...(array) ($this->inputs['options'] ?? []), ...(array) ($this->private_inputs['options'] ?? [])];
    }

    /**
     * The runs this run was built from (inputs['runs'], a TakesRunInputs tool), in order, each with its encrypted
     * keys (the label) put back. Empty for any other tool.
     *
     * @return list<array<string, mixed>>
     */
    public function sourceRuns(): array
    {
        $private = array_values((array) ($this->private_inputs['runs'] ?? []));
        $rows = [];
        foreach (array_values(array_filter((array) ($this->inputs['runs'] ?? []), 'is_array')) as $index => $row) {
            $rows[] = [...$row, ...(is_array($private[$index] ?? null) ? $private[$index] : [])];
        }

        return $rows;
    }

    public function scope(): MorphTo
    {
        return $this->morphTo();
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
