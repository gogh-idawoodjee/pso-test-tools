<?php

use App\Support\DatasetSettings;
use App\Tools\RegionAvailability\AccountSettingsFile;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/accounts-'.uniqid();
});

afterEach(function () {
    if (is_dir($this->dir)) {
        File::deleteDirectory($this->dir);
    }
});

it('writes the settings under the pso-tools key names and creates the folder', function () {
    $settings = DatasetSettings::fromArray([
        'display_name' => 'SaskTel',
        'exclude_resource_types' => ['LONGCYCLE', 'SHORTCYCLE'],
        'resource_type_names' => ['DISTRICTTECH' => 'district tech'],
        'attribute_labels' => ['district' => 'District', 'supervisor_email' => 'Supervisor Email'],
        'time_zone' => 'America/Regina',
    ]);

    $path = AccountSettingsFile::write($settings, $this->dir, 'SATE');

    expect($path)->toBe($this->dir.'/SATE.json');
    $json = json_decode(file_get_contents($path), true);
    expect($json['display_name'])->toBe('SaskTel')
        ->and($json['attributes'])->toBe(['district' => 'District', 'supervisor_email' => 'Supervisor Email'])
        ->and($json['exclude_resource_types'])->toBe(['LONGCYCLE', 'SHORTCYCLE'])
        ->and($json['resource_type_names'])->toBe(['DISTRICTTECH' => 'district tech'])
        ->and($json['region_pivot'])->toBe(['red_below' => 1, 'amber_below' => 3])
        ->and($json['time_zone'])->toBe('America/Regina')
        ->and($json)->not->toHaveKeys(['attribute_labels', 'eod_cutoff_hour', 'dataset_display_name']);
});

it('writes an empty object, never a list, when there are no settings', function () {
    AccountSettingsFile::write(null, $this->dir, 'ACME');

    expect(trim(file_get_contents($this->dir.'/ACME.json')))->toBe('{}');
});

it('leaves empty maps out so the script never meets a JSON list where it expects a map', function () {
    AccountSettingsFile::write(DatasetSettings::fromArray(['display_name' => 'Acme']), $this->dir, 'ACME');

    $json = json_decode(file_get_contents($this->dir.'/ACME.json'), true);
    expect($json)->toHaveKeys(['display_name', 'coverage_km', 'region_pivot'])
        ->and($json)->not->toHaveKeys(['attributes', 'exclude_resource_types', 'resource_type_names', 'notes', 'regions']);
});

it('cleans the account name', function (?string $raw, string $expected) {
    expect(AccountSettingsFile::accountName($raw))->toBe($expected);
})->with([
    ['sate', 'SATE'],
    ['ROGE', 'ROGE'],
    ['sa te-1', 'SATE1'],
    ['   ', 'ACCOUNT'],
    [null, 'ACCOUNT'],
]);
