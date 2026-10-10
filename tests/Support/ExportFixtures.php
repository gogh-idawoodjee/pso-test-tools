<?php

use App\Enums\ExportKind;
use App\Models\ExportFile;

function scheduleXml(string $datasetId = 'NORTH', string $datetime = '2026-10-10T07:12:00', int $activities = 2, int $resources = 1): string
{
    $body = "<Input_Reference><id>x</id><datetime>{$datetime}</datetime><dataset_id>{$datasetId}</dataset_id></Input_Reference>";
    for ($i = 0; $i < $activities; $i++) { $body .= "<Activity><id>A{$i}</id></Activity>"; }
    for ($i = 0; $i < $resources; $i++) { $body .= "<Resources><id>R{$i}</id></Resources>"; }
    $path = tempnam(sys_get_temp_dir(), 'exp_').'.xml';
    file_put_contents($path, '<?xml version="1.0"?><dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">'.$body.'</dsScheduleData>');

    return $path;
}

/** What a PSO plan export looks like: allocations and a Plan block, no Input_Reference, Activity or Resources. */
function planXml(int $allocations = 3, string $datasetId = 'NORTH', string $referenceTime = '2026-10-04T08:06:33+00:00'): string
{
    $body = '';
    for ($i = 0; $i < $allocations; $i++) {
        $body .= "<Allocation_Data><activity_id>{$i}</activity_id></Allocation_Data><Allocation><activity_id>{$i}</activity_id><resource_id>1001</resource_id></Allocation>";
    }
    $body .= "<Plan><id>11049233</id><input_reference_date_time>{$referenceTime}</input_reference_date_time><dataset_id>{$datasetId}</dataset_id></Plan>";
    $path = tempnam(sys_get_temp_dir(), 'exp_').'.xml';
    file_put_contents($path, '<?xml version="1.0"?><dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">'.$body.'</dsScheduleData>');

    return $path;
}

function sysXml(): string
{
    return \Tests\Support\SysFileBuilder::make()
        ->version('6.13.0.0', 'Creation', '2025-01-01 00:00:00')
        ->version('6.14.0.0', 'Upgrade from 6.13.0.0', '2026-03-01 00:00:00')
        ->parameter('ORG', 'P1', '1')
        ->parameter('ORG', 'P2', '2')
        ->exceptionType('ORG', 'E1', true)
        ->user('admin')
        ->write('export');
}

function zipOf(string $memberPath, string $memberName): string
{
    $path = tempnam(sys_get_temp_dir(), 'exp_').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($memberPath, $memberName);
    $zip->close();

    return $path;
}

function fileOfKind(ExportKind $kind, string $contentType): ExportFile
{
    return ExportFile::factory()->make(['kind' => $kind, 'content_type' => $contentType]);
}
