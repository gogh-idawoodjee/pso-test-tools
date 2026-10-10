<?php

namespace App\Support\ReferenceValues;

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies one sync result to a dataset's reference values.
 *
 * Rules (spec 5.2): unknown id -> create as pso; known and not locally
 * modified -> overwrite label/attributes; known and locally modified ->
 * bump last_seen_at only; absent from the response -> untouched, counted
 * as missing. Nothing is ever deleted.
 */
class ReferenceValuesUpserter
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $tables keyed by wire entity name
     * @return array<string, array{created: int, updated: int, kept: int, missing: int}> keyed by ReferenceEntityType value
     */
    public function apply(Dataset $dataset, array $tables, CarbonImmutable $now): array
    {
        $summary = [];

        DB::transaction(function () use ($dataset, $tables, $now, &$summary): void {
            foreach (ReferenceEntityType::cases() as $type) {
                $summary[$type->value] = $this->applyType($dataset, $type, $tables[$type->psoEntity()] ?? [], $now);
            }
        });

        return $summary;
    }

    private function applyType(Dataset $dataset, ReferenceEntityType $type, array $rows, CarbonImmutable $now): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'kept' => 0, 'missing' => 0];

        $existing = DatasetReferenceValue::withoutGlobalScopes()
            ->where('dataset_id', $dataset->id)
            ->where('entity_type', $type)
            ->get()
            ->keyBy('pso_id');

        $seen = [];

        foreach ($rows as $row) {
            $psoId = trim((string) ($row['id'] ?? ''));
            if ($psoId === '') {
                continue;
            }
            $seen[$psoId] = true;

            $label = Str::limit(trim((string) ($row['description'] ?? '')) ?: $psoId, 255, '');
            $attributes = $this->attributesFor($type, $row);

            /** @var DatasetReferenceValue|null $current */
            $current = $existing->get($psoId);

            if ($current === null) {
                DatasetReferenceValue::withoutGlobalScopes()->create([
                    'dataset_id' => $dataset->id,
                    'entity_type' => $type,
                    'pso_id' => $psoId,
                    'label' => $label,
                    'attributes' => $attributes,
                    'origin' => ReferenceValueOrigin::Pso,
                    'last_seen_at' => $now,
                    'is_locally_modified' => false,
                ]);
                $counts['created']++;

                continue;
            }

            if ($current->is_locally_modified) {
                $current->forceFill(['origin' => ReferenceValueOrigin::Pso, 'last_seen_at' => $now])->saveQuietly();
                $counts['kept']++;

                continue;
            }

            $current->forceFill([
                'origin' => ReferenceValueOrigin::Pso,
                'label' => $label,
                'attributes' => $attributes,
                'last_seen_at' => $now,
            ])->saveQuietly();
            $counts['updated']++;
        }

        // $existing was loaded before the loop, so a manual row promoted in
        // this run still reads as manual here and stays out of the count.
        $counts['missing'] = $existing
            ->filter(static fn (DatasetReferenceValue $row): bool => $row->origin === ReferenceValueOrigin::Pso && ! isset($seen[$row->pso_id]))
            ->count();

        return $counts;
    }

    private function attributesFor(ReferenceEntityType $type, array $row): ?array
    {
        $keys = $type->attributeKeys();
        if ($keys === []) {
            return null;
        }

        $attributes = [];
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            $attributes[$key] = match ($key) {
                'priority', 'base_duration' => is_numeric($value) ? (int) $value : null,
                'base_value' => is_numeric($value) ? (float) $value : null,
                default => $value,
            };
        }

        return $attributes;
    }
}
