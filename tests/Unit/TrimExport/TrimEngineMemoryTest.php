<?php

use App\Support\TrimExport\TrimEngine;
use App\Support\TrimExport\TrimPlan;

it('keeps memory to ids on a large generated export, in xml and json', function (string $format) {
    $path = sys_get_temp_dir().'/trim-large-'.uniqid().'.'.$format;
    $handle = fopen($path, 'wb');
    fwrite($handle, $format === 'xml' ? '<dsScheduleData>' : '{"dsScheduleData": {"Activity": [');
    for ($index = 0; $index < 60000; $index++) {
        $row = ['id' => "A{$index}", 'location_id' => 'L'.($index % 500), 'activity_type_id' => 'T'.($index % 7), 'description' => str_repeat('x', 120)];
        fwrite($handle, $format === 'xml'
            ? "\n  <Activity><id>{$row['id']}</id><location_id>{$row['location_id']}</location_id><activity_type_id>{$row['activity_type_id']}</activity_type_id><description>{$row['description']}</description></Activity>"
            : ($index === 0 ? '' : ',')."\n    ".json_encode($row));
    }
    fwrite($handle, $format === 'xml' ? "\n</dsScheduleData>\n" : "\n  ]}}\n");
    fclose($handle);

    memory_reset_peak_usage();
    $before = memory_get_usage();

    try {
        $report = app(TrimEngine::class)->run($path, $format, TrimPlan::fromOptions(['activity_type_ids' => ['T3']], 'UTC'), $path.'.out');

        expect(filesize($path))->toBeGreaterThan(10 * 1024 * 1024)
            ->and($report->tables['Activity'])->toBe(['before' => 60000, 'kept' => 8571])
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(64 * 1024 * 1024);
    } finally {
        @unlink($path);
        @unlink($path.'.out');
    }
})->with(['xml', 'json']);
