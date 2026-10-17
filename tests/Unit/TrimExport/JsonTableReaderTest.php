<?php

use App\Support\TrimExport\Readers\JsonTableReader;

it('cuts a json export into head, tables, rows and tail and gives it back byte for byte', function () {
    $json = "{\n  \"dsScheduleData\": {\n    \"Input_Reference\": {\"datetime\": \"2026-10-04T08:00:00\"},\n"
        ."    \"Activity\": [\n      {\"id\": \"A1\", \"note\": \"a } b\"},\n      {\"id\": \"A2\"}\n    ],\n"
        ."    \"Resources\": [],\n    \"Odd\": null\n  }\n}\n";

    $segments = iterator_to_array(app(JsonTableReader::class)->segments(trimFixtureFile($json, 'json')), false);

    expect(joinSegments($segments))->toBe($json)
        ->and(segmentShape($segments))->toBe([
            'head', 'table_open Input_Reference', 'row Input_Reference', 'table_close Input_Reference',
            'table_open Activity', 'row Activity', 'row Activity', 'table_close Activity',
            'table_open Resources', 'table_close Resources', 'table_open Odd', 'table_close Odd', 'tail',
        ])
        ->and($segments[0]->body)->toBe("{\n  \"dsScheduleData\": {")
        ->and($segments[1]->singleObject)->toBeTrue()
        ->and($segments[1]->prefix)->toBe("\n    ")
        ->and($segments[1]->body)->toBe('"Input_Reference": ')
        ->and($segments[4]->prefix)->toBe(",\n    ")
        ->and($segments[4]->body)->toBe('"Activity": [')
        ->and($segments[5]->prefix)->toBe("\n      ")
        ->and($segments[6]->prefix)->toBe(",\n      ")
        ->and($segments[7]->prefix)->toBe("\n    ")
        ->and($segments[7]->body)->toBe(']')
        ->and($segments[10]->scalar)->toBeTrue()
        ->and($segments[10]->body)->toBe('"Odd": null')
        ->and($segments[12]->body)->toBe("\n  }\n}\n")
        ->and($segments[12]->offset)->toBe(strlen($json));
});

it('keeps nested objects, lists and escaped strings inside one row', function () {
    $json = "\xEF\xBB\xBF{\"dsScheduleData\":{\"Activity\":[{\"id\":\"A\\\"1\\\\\",\"note\":\"\\u00e9 ] }\",\"extra\":{\"list\":[1,{\"deep\":[]}]}},{\"id\":\"A2\"}]}}";
    $reader = app(JsonTableReader::class);
    $segments = iterator_to_array($reader->segments(trimFixtureFile($json, 'json')), false);

    expect(joinSegments($segments))->toBe($json)
        ->and(segmentShape($segments))->toBe(['head', 'table_open Activity', 'row Activity', 'row Activity', 'table_close Activity', 'tail'])
        ->and($reader->fieldsOf($segments[2]->body, ['id', 'note']))->toBe(['id' => 'A"1\\', 'note' => 'é ] }']);
});

it('reads the 6.15 ScheduleData wrapper', function () {
    $json = '{"ScheduleData":{"Activity":[{"id":14420},{"id":14421}]}}';
    $segments = iterator_to_array(app(JsonTableReader::class)->segments(trimFixtureFile($json, 'json')), false);

    expect(segmentShape($segments))->toBe(['head', 'table_open Activity', 'row Activity', 'row Activity', 'table_close Activity', 'tail'])
        ->and(joinSegments($segments))->toBe($json);
});

it('reads native types as strings, skipping null and empty values', function () {
    expect(app(JsonTableReader::class)->fieldsOf('{"id": 14420, "split_allowed": false, "duration": 1.5, "location_id": null, "activity_type_id": ""}', ['id', 'split_allowed', 'duration', 'location_id', 'activity_type_id']))
        ->toBe(['id' => '14420', 'split_allowed' => 'false', 'duration' => '1.5']);
});

it('replaces one field of a json row and leaves a row without it alone', function () {
    $reader = app(JsonTableReader::class);

    expect($reader->replaceField('{"id": "x", "datetime" : "2026-10-04T08:00:00", "dataset_id": "NORTH"}', 'datetime', '2026-10-05T06:00:00'))
        ->toBe('{"id": "x", "datetime" : "2026-10-05T06:00:00", "dataset_id": "NORTH"}')
        ->and($reader->replaceField('{"id": "x"}', 'datetime', 'y'))->toBe('{"id": "x"}');
});

it('refuses json with no dsScheduleData wrapper, and a truncated file', function () {
    $reader = app(JsonTableReader::class);

    expect(fn () => iterator_to_array($reader->segments(trimFixtureFile('{"Other": {"Activity": []}}', 'json'))))
        ->toThrow(RuntimeException::class, 'no dsScheduleData wrapper');
    expect(fn () => iterator_to_array($reader->segments(trimFixtureFile('{"dsScheduleData": {"Activity": [{"id": "A1"}', 'json'))))
        ->toThrow(RuntimeException::class, 'looks truncated');
});

it('stops looking for the wrapper once the head passes the cap, without holding the file', function () {
    $path = trimFixtureFile('{"Other": [', 'json');
    $handle = fopen($path, 'ab');
    $member = '{"x": "'.str_repeat('y', 4000).'"},';
    for ($written = 0; $written <= JsonTableReader::MAX_HEAD_BYTES + 16777216; $written += strlen($member)) {
        fwrite($handle, $member);
    }
    fwrite($handle, '{}], "dsScheduleData": {}}');
    fclose($handle);

    memory_reset_peak_usage();
    $before = memory_get_usage(true);

    expect(fn () => iterator_to_array(app(JsonTableReader::class)->segments($path)))
        ->toThrow(RuntimeException::class, 'no dsScheduleData wrapper in its first 16 MB')
        ->and(memory_get_peak_usage(true) - $before)->toBeLessThan(JsonTableReader::MAX_HEAD_BYTES + 8388608)
        ->and(filesize($path))->toBeGreaterThan(JsonTableReader::MAX_HEAD_BYTES + 16777216);
});

it('refuses a row that never closes once it passes the segment cap, without holding the rest of the file', function () {
    $path = trimFixtureFile('{"dsScheduleData": {"Activity": [{"id": "A1"', 'json');
    $handle = fopen($path, 'ab');
    $chunk = str_repeat(', "note": "'.str_repeat('x', 1000).'"', 256);
    for ($written = 0; $written <= JsonTableReader::MAX_SEGMENT_BYTES + 16777216; $written += strlen($chunk)) {
        fwrite($handle, $chunk);
    }
    fclose($handle);

    $before = memory_get_usage(true);

    expect(fn () => iterator_to_array(app(JsonTableReader::class)->segments($path)))
        ->toThrow(RuntimeException::class, 'MB long that never ends. It looks truncated or damaged.')
        ->and(memory_get_peak_usage(true) - $before)->toBeLessThan(JsonTableReader::MAX_SEGMENT_BYTES + 8388608)
        ->and(filesize($path))->toBeGreaterThan(JsonTableReader::MAX_SEGMENT_BYTES + 16777216);
});
