<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\ExportStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SysFileBuilder;

/** A fake DsSystemData with $polygons axis-aligned rectangles G01, G02, ... (weighting 0.4); G01 covers the first trip of tuningCsv(). */
function polygonSysXml(int $polygons = 1): string
{
    $builder = SysFileBuilder::make()->version('6.14.0.0', 'Creation', '2026-01-01 00:00:00');
    for ($number = 1; $number <= $polygons; $number++) {
        $south = 50.0 + ($number - 1) * 0.2;
        $north = $south + 0.1;
        $id = sprintf('G%02d', $number);
        $builder->row('Polygon', [
            'id' => $id, 'description' => 'Grid w0.40 | test',
            'latitude_1' => (string) $south, 'longitude_1' => '-101.00', 'latitude_2' => (string) $south, 'longitude_2' => '-100.80',
            'latitude_3' => (string) $north, 'longitude_3' => '-100.80', 'latitude_4' => (string) $north, 'longitude_4' => '-101.00',
        ])->row('Travel_Time_Polygon', ['polygon_id' => $id, 'weighting' => '0.4']);
    }

    return $builder->write('tuning');
}

/** A SYS export on $scope (an environment, or a user for Unfiled) with the file's bytes on the exports disk. */
function storedSys(Model $scope, string $xmlPath, bool $zipped = false, array $attributes = []): ExportFile
{
    $file = ExportFile::factory()->for($scope, 'scope')->sys()->create([
        'content_type' => $zipped ? 'zip' : 'xml', 'original_filename' => $zipped ? 'PSO_Sys.zip' : 'PSO_Sys.xml', ...$attributes,
    ]);
    $source = $zipped ? zipOf($xmlPath, 'PSO_Sys.xml') : $xmlPath;
    Storage::disk((string) config('exports.disk'))->put(app(ExportStorage::class)->objectKey($file), (string) file_get_contents($source));

    return $file;
}

/** A plan travels CSV of four synthetic legs (no customer data); Google columns unless $google is false. */
function tuningCsv(array $psoSeconds = [2400, 2700, 630, 900], bool $google = true): string
{
    $trips = [
        [50.02, -100.98, 50.08, -100.82, 900, 15.0],
        [50.22, -100.58, 50.27, -100.32, 1500, 22.0],
        [50.70, -100.50, 50.75, -100.40, 600, 9.5],
        [50.41, -100.19, 50.44, -100.01, 840, 14.0],
    ];
    $header = ['Resource ID', 'GB (Previous Activity)', 'GB (Destination Activity)', 'Start Location Latitude', 'Start Location Longitude',
        'End Location Latitude', 'End Location Longitude', 'Distance (km)', 'Expected Travel Time (sec)', 'Merged Fragments'];
    if ($google) {
        array_push($header, 'Google Duration (min)', 'Google Duration (sec)', 'Google Distance (km)', 'Validation Error');
    }
    $lines = [implode(',', $header)];
    foreach ($trips as $index => [$fromLat, $fromLng, $toLat, $toLng, $googleSeconds, $kilometres]) {
        $row = ['R'.($index + 1), 'AA1', 'BB2', $fromLat, $fromLng, $toLat, $toLng, $kilometres, $psoSeconds[$index], 1];
        if ($google) {
            array_push($row, $googleSeconds / 60, $googleSeconds, $kilometres, '');
        }
        $lines[] = implode(',', array_map('strval', $row));
    }

    return implode("\n", $lines)."\n";
}

/** A finished Plan travels run on $scope, checked with Google unless the summary says otherwise. */
function planTravelsRun(Model $scope, array $attributes = [], ?string $csv = null): ToolRun
{
    return ToolRun::factory()->for($scope, 'scope')->create([
        'tool' => ToolId::PlanTravels,
        'status' => ToolRunStatus::Succeeded,
        'result' => ['html' => '<p>plan travels</p>', 'csv' => $csv ?? tuningCsv()],
        'summary' => ['leg_count' => 4, 'review_count' => 2, 'first_date' => '2026-10-05', 'last_date' => '2026-10-05', 'days' => 1,
            'google' => ['cache' => 4, 'google' => 0, 'error' => 0], 'line' => '4 legs for 4 resources, 2026-10-05'],
        ...$attributes,
    ]);
}
