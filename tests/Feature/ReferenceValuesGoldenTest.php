<?php

use App\Enums\ReferenceEntityType;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Support\ReferenceValues\ReferenceValuesUpserter;
use Carbon\CarbonImmutable;

/**
 * Golden check against a real dataset. Point REFERENCE_VALUES_SAMPLE_JSON at a
 * pso-services /dataset/reference-values response saved to disk (customer data,
 * kept outside the repo) and put the expected per-type counts in a sibling
 * <name>.expected.json: {"region": 12, "skill": 40, ...}.
 */
it('upserts a real dataset export to the expected counts', function () {
    $samplePath = env('REFERENCE_VALUES_SAMPLE_JSON');
    if (! $samplePath || ! is_file($samplePath)) {
        $this->markTestSkipped('Set REFERENCE_VALUES_SAMPLE_JSON to a pso-services reference-values response saved to disk.');
    }
    $expectedPath = preg_replace('/\.json$/', '.expected.json', $samplePath);
    if (! is_file($expectedPath)) {
        $this->markTestSkipped("Missing {$expectedPath} with per-type expected counts.");
    }

    $tables = json_decode(file_get_contents($samplePath), true)['data']['tables'];
    $expected = json_decode(file_get_contents($expectedPath), true);
    $dataset = Dataset::factory()->create();

    app(ReferenceValuesUpserter::class)->apply($dataset, $tables, CarbonImmutable::now());

    foreach (ReferenceEntityType::cases() as $type) {
        expect(DatasetReferenceValue::withoutGlobalScopes()->where('dataset_id', $dataset->id)->ofType($type)->count())
            ->toBe($expected[$type->value], $type->value);
    }
});
