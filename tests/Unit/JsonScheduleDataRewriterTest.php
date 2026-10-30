<?php

use App\Support\ScheduleData\BroadcastInjection;
use App\Support\ScheduleData\BroadcastSettings;
use App\Support\ScheduleData\InputReferenceIdentity;
use App\Support\ScheduleData\JsonScheduleDataRewriter;
use App\Support\ScheduleData\MissingScheduleDataWrapperException;
use App\Support\ScheduleData\RewriteReport;

/**
 * @return array{0: string, 1: RewriteReport}
 */
function rewriteJson(string $json, ?string $datasetIdOverride = null, ?BroadcastInjection $injection = null, bool $stripExisting = true, ?InputReferenceIdentity $identity = null): array
{
    $source = tempnam(sys_get_temp_dir(), 'rwsrc');
    $destination = tempnam(sys_get_temp_dir(), 'rwdst');
    file_put_contents($source, $json);

    try {
        $report = app(JsonScheduleDataRewriter::class)->rewrite($source, $destination, $datasetIdOverride, $injection, $stripExisting, $identity);

        return [file_get_contents($destination), $report];
    } finally {
        @unlink($source);
        @unlink($destination);
    }
}

function webhookInjection(): BroadcastInjection
{
    return new BroadcastInjection(BroadcastSettings::shipped(), 'https://webhook.example.test/pso-env');
}

it('injects a typed Broadcast and parameters into a ScheduleData file', function () {
    [$output, $report] = rewriteJson('{"ScheduleData":{"Resources":[{"id":"R1"}]}}', null, webhookInjection());

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $broadcast = $decoded['ScheduleData']['Broadcast'];

    expect($report->format)->toBe('ScheduleData');
    expect($decoded['ScheduleData']['Resources'])->toBe([['id' => 'R1']]);
    expect($broadcast)->toHaveCount(1);
    expect($broadcast[0])->toBe([
        'id' => 'WEBHOOK_COMPLETE',
        'active' => true,
        'broadcast_type_id' => 'REST',
        'plan_type' => 'CHANGE',
        'minimum_plan_quality' => 85,
        'minimum_step_interval' => 1,
        'once_only' => false,
        'maximum_wait' => 'PT1M',
        'maximum_frequency' => 'PT10M',
        'allocation_type' => 1,
    ]);

    $parameters = $decoded['ScheduleData']['Broadcast_Parameter'];
    expect(array_column($parameters, 'parameter_name'))->toBe(['etag', 'httpmethod', 'mediatype', 'required_tables', 'url']);
    expect($parameters[4]['parameter_value'])->toBe('https://webhook.example.test/pso-env');
    expect($parameters[2]['parameter_value'])->toBe('application/json');
});

it('injects string-typed rows and a single Broadcast object into a legacy dsScheduleData file', function () {
    [$output, $report] = rewriteJson('{"dsScheduleData":{"Resources":{"id":"R1"}}}', null, webhookInjection());

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $broadcast = $decoded['dsScheduleData']['Broadcast'];

    expect($report->format)->toBe('dsScheduleData');
    expect($report->wrapperFound)->toBeTrue();
    expect($broadcast['active'])->toBe('true');
    expect($broadcast['minimum_plan_quality'])->toBe('85');
    expect($broadcast['once_only'])->toBe('false');
    expect($broadcast['maximum_wait'])->toBe('PT1M');
    expect($broadcast['maximum_frequency'])->toBe('PT10M');
    expect(array_is_list($broadcast))->toBeFalse();
    expect($decoded['dsScheduleData']['Broadcast_Parameter'])->toHaveCount(5);
});

it('strips existing Broadcast and Broadcast_Parameter rows and reports the counts', function () {
    $json = '{"ScheduleData":{"Broadcast":[{"id":"OLD1"},{"id":"OLD2"}],"Resources":[{"id":"R1"}],'
        .'"Broadcast_Parameter":[{"broadcast_id":"OLD1","parameter_name":"url","parameter_value":"x"}]}}';

    [$output, $report] = rewriteJson($json, null, webhookInjection());

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($report->strippedBroadcasts)->toBe(2);
    expect($report->strippedBroadcastParameters)->toBe(1);
    expect($decoded['ScheduleData']['Broadcast'])->toHaveCount(1);
    expect($decoded['ScheduleData']['Broadcast'][0]['id'])->toBe('WEBHOOK_COMPLETE');
    expect($output)->not->toContain('OLD1');
});

it('counts a legacy single-object Broadcast as one row', function () {
    [, $report] = rewriteJson('{"dsScheduleData":{"Broadcast":{"id":"OLD"},"Resources":{"id":"R1"}}}', null, webhookInjection());

    expect($report->strippedBroadcasts)->toBe(1);
});

it('copies untouched entities byte for byte', function () {
    $resources = '"Resources" : [ { "id":"Ré", "x":1.50, "y" : 1E3 } ]';
    $json = "{\r\n  \"ScheduleData\" : {\r\n    {$resources}\r\n  }\r\n}\r\n";

    [$output] = rewriteJson($json, null, webhookInjection());

    expect($output)->toContain($resources);
    expect($output)->toStartWith("{\r\n  \"ScheduleData\" : {\r\n    ");
    expect($output)->toEndWith("}\r\n}\r\n");
});

it('returns the input unchanged when there is nothing to do', function () {
    $json = "{ \"ScheduleData\" : { \"Resources\": [ {\"id\":\"R1\"} ] } }\n";

    [$output, $report] = rewriteJson($json);

    expect($output)->toBe($json);
    expect($report->datasetIdChanged)->toBeFalse();
});

it('tolerates a UTF-8 BOM and CRLF line endings', function () {
    $json = "\xEF\xBB\xBF{\r\n\"ScheduleData\":{\r\n\"Resources\":[]\r\n}\r\n}";

    [$output, $report] = rewriteJson($json, null, webhookInjection());

    expect($report->format)->toBe('ScheduleData');
    expect(str_starts_with($output, "\xEF\xBB\xBF"))->toBeTrue();
    expect(json_decode(substr($output, 3), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('ScheduleData.Broadcast');
});

it('rewrites a minified single-line file', function () {
    [$output] = rewriteJson('{"ScheduleData":{"Broadcast":[{"id":"OLD"}]}}', null, webhookInjection());

    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData']['Broadcast'][0]['id'])->toBe('WEBHOOK_COMPLETE');
});

it('does not strip text that only looks like a Broadcast key', function () {
    $json = '{"ScheduleData":{"Activity":[{"id":"A1","description":"see \"Broadcast\": and \"Broadcast_Parameter\":{}"}],'
        .'"Resources":[{"id":"R1","Broadcast":{"nested":true}}]}}';

    [$output, $report] = rewriteJson($json, null, webhookInjection());

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($report->strippedBroadcasts)->toBe(0);
    expect($report->strippedBroadcastParameters)->toBe(0);
    expect($decoded['ScheduleData']['Activity'][0]['description'])->toBe('see "Broadcast": and "Broadcast_Parameter":{}');
    expect($decoded['ScheduleData']['Resources'][0]['Broadcast'])->toBe(['nested' => true]);
});

it('replaces dataset_id in every Input_Reference row and reports the originals', function () {
    $json = '{"ScheduleData":{"Input_Reference":[{"datetime":"2026-10-11T00:00:00Z","dataset_id":"OLD1"},{"dataset_id":"OLD2"}],"Resources":[{"dataset_id":"KEEP"}]}}';

    [$output, $report] = rewriteJson($json, 'NEW');

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['ScheduleData']['Input_Reference'][0]['dataset_id'])->toBe('NEW');
    expect($decoded['ScheduleData']['Input_Reference'][1]['dataset_id'])->toBe('NEW');
    expect($decoded['ScheduleData']['Input_Reference'][0]['datetime'])->toBe('2026-10-11T00:00:00Z');
    expect($decoded['ScheduleData']['Resources'][0]['dataset_id'])->toBe('KEEP');
    expect($report->datasetIds)->toBe(['OLD1', 'OLD2']);
    expect($report->datasetIdChanged)->toBeTrue();
});

it('handles a legacy single-object Input_Reference', function () {
    [$output, $report] = rewriteJson('{"dsScheduleData":{"Input_Reference":{"dataset_id":"OLD"}}}', 'NEW');

    expect(json_decode($output, true)['dsScheduleData']['Input_Reference']['dataset_id'])->toBe('NEW');
    expect($report->datasetIds)->toBe(['OLD']);
});

it('reports no change when the override equals the existing value', function () {
    [, $report] = rewriteJson('{"ScheduleData":{"Input_Reference":[{"dataset_id":"SAME"}]}}', 'SAME');

    expect($report->datasetIdChanged)->toBeFalse();
    expect($report->datasetIds)->toBe(['SAME']);
});

it('reports the existing dataset ids even without an override', function () {
    [, $report] = rewriteJson('{"ScheduleData":{"Input_Reference":[{"dataset_id":"ONLY"}]}}', null, webhookInjection());

    expect($report->datasetIds)->toBe(['ONLY']);
    expect($report->datasetIdChanged)->toBeFalse();
});

it('escapes an override containing JSON special characters', function () {
    $override = 'A"B\\C/D & <E>';

    [$output] = rewriteJson('{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}]}}', $override);

    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData']['Input_Reference'][0]['dataset_id'])->toBe($override);
});

it('does nothing to a file with no Input_Reference when only an override is given', function () {
    [$output, $report] = rewriteJson('{"ScheduleData":{"Resources":[]}}', 'NEW');

    expect($output)->toBe('{"ScheduleData":{"Resources":[]}}');
    expect($report->datasetIds)->toBe([]);
    expect($report->datasetIdChanged)->toBeFalse();
});

it('injects into an empty wrapper', function () {
    [$output] = rewriteJson('{"ScheduleData":{}}', null, webhookInjection());

    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData'])->toHaveKeys(['Broadcast', 'Broadcast_Parameter']);
});

it('fails with the script message when there is no ScheduleData wrapper', function () {
    expect(static fn () => rewriteJson('{"Other":{"Resources":[]}}', null, webhookInjection()))
        ->toThrow(MissingScheduleDataWrapperException::class, "Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper");
});

it('rewrites a large file in constant memory', function () {
    $source = tempnam(sys_get_temp_dir(), 'bigsrc');
    $destination = tempnam(sys_get_temp_dir(), 'bigdst');

    $handle = fopen($source, 'wb');
    fwrite($handle, '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}],"Activity":[');
    $row = '{"id":"ACT-000000","description":"'.str_repeat('x', 200).'","priority":5},';

    for ($index = 0; $index < 100000; $index++) {
        fwrite($handle, $row);
    }

    fwrite($handle, '{"id":"LAST"}]}}');
    fclose($handle);

    memory_reset_peak_usage();
    $before = memory_get_usage();

    try {
        $report = app(JsonScheduleDataRewriter::class)->rewrite($source, $destination, 'NEW', webhookInjection());
        $growth = memory_get_peak_usage() - $before;

        expect(filesize($source))->toBeGreaterThan(20 * 1024 * 1024);
        expect($report->bytesWritten)->toBe(filesize($destination));
        expect($growth)->toBeLessThan(16 * 1024 * 1024);
    } finally {
        @unlink($source);
        @unlink($destination);
    }
});

it('keeps the whitespace around the separators between kept members', function () {
    $json = "{\"ScheduleData\":{\"Input_Reference\":[{\"dataset_id\":\"D\"}] ,\n  \"Resources\":[] ,\"Activity\":[]}}";

    [$output] = rewriteJson($json, 'D');

    expect($output)->toBe($json);
});

it('keeps the separator whitespace when a stripped member sits between kept ones', function () {
    $json = "{\"ScheduleData\":{\n  \"Resources\":[],\n  \"Broadcast\":[{\"id\":\"OLD\"}],\n  \"Activity\":[]\n}}";

    [$output] = rewriteJson($json, null, webhookInjection());

    expect($output)->toStartWith("{\"ScheduleData\":{\n  \"Resources\":[],\n  \"Activity\":[],\"Broadcast\":");
    expect($output)->toEndWith("\n}}");
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData'])->toHaveKeys(['Resources', 'Activity', 'Broadcast', 'Broadcast_Parameter']);
});

it('only replaces dataset_id at the row level of Input_Reference', function () {
    $json = '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD","extra":{"dataset_id":"DEEP"}}]}}';

    [$output, $report] = rewriteJson($json, 'NEW');

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['ScheduleData']['Input_Reference'][0]['dataset_id'])->toBe('NEW');
    expect($decoded['ScheduleData']['Input_Reference'][0]['extra']['dataset_id'])->toBe('DEEP');
    expect($report->datasetIds)->toBe(['OLD']);
});

it('fails when the file ends inside the wrapper', function () {
    expect(static fn () => rewriteJson('{"ScheduleData":{"Resources":[{"id":"R1"}', null, webhookInjection()))
        ->toThrow(RuntimeException::class, 'ended inside');
});

it('never modifies the source file', function () {
    $json = '{"ScheduleData":{"Broadcast":[{"id":"OLD"}],"Input_Reference":[{"dataset_id":"OLD"}]}}';
    $source = tempnam(sys_get_temp_dir(), 'rwsrc');
    $destination = tempnam(sys_get_temp_dir(), 'rwdst');
    file_put_contents($source, $json);

    try {
        app(JsonScheduleDataRewriter::class)->rewrite($source, $destination, 'NEW', webhookInjection());

        expect(file_get_contents($source))->toBe($json);
    } finally {
        @unlink($source);
        @unlink($destination);
    }
});

it('keeps existing Broadcast and Broadcast_Parameter rows byte for byte when stripping is off and nothing is injected', function () {
    $json = "{\"ScheduleData\":{\"Input_Reference\":[{\"dataset_id\":\"OLD\"}],\n  \"Broadcast\" : [ {\"id\":\"MINE\", \"active\":true} ],\n  \"Broadcast_Parameter\":[{\"broadcast_id\":\"MINE\",\"parameter_name\":\"url\",\"parameter_value\":\"https://x/y\"}]\n}}";

    [$output, $report] = rewriteJson($json, 'NEW', null, stripExisting: false);

    expect($output)->toBe(str_replace('"OLD"', '"NEW"', $json));
    expect($report->strippedBroadcasts)->toBe(0);
    expect($report->strippedBroadcastParameters)->toBe(0);
    expect($report->datasetIdChanged)->toBeTrue();
});

it('copies a file without a wrapper unchanged when only an override is given', function () {
    $json = '{"Other":{"Input_Reference":[{"dataset_id":"OLD"}]}}';

    [$output, $report] = rewriteJson($json, 'NEW');

    expect($output)->toBe($json);
    expect($report->format)->toBeNull();
    expect($report->wrapperFound)->toBeFalse();
    expect($report->datasetIds)->toBe([]);
    expect($report->datasetIdChanged)->toBeFalse();
    expect($report->bytesWritten)->toBe(strlen($json));
});

it('refuses to write over the source file', function () {
    $json = '{"ScheduleData":{"Input_Reference":[{"dataset_id":"OLD"}]}}';
    $source = tempnam(sys_get_temp_dir(), 'rwsrc');
    file_put_contents($source, $json);

    try {
        expect(static fn () => app(JsonScheduleDataRewriter::class)->rewrite($source, $source, 'NEW', webhookInjection()))
            ->toThrow(RuntimeException::class, 'cannot be written over the original');
        expect(file_get_contents($source))->toBe($json);
    } finally {
        @unlink($source);
    }
});

it('strips existing Broadcast rows without injecting when asked, leaving every other byte as it was', function () {
    $kept = "{\"ScheduleData\":{\"Input_Reference\":[{\"dataset_id\":\"OLD\"}],\n  \"Resources\" : [ {\"id\":\"R\u00e9\"} ]";
    $json = $kept.",\n  \"Broadcast\" : [ {\"id\":\"MINE\"} ],\n  \"Broadcast_Parameter\":[{\"broadcast_id\":\"MINE\"},{\"broadcast_id\":\"MINE\"}]\n}}";

    [$output, $report] = rewriteJson($json);

    expect($output)->toBe($kept."\n}}");
    expect($report->strippedBroadcasts)->toBe(1);
    expect($report->strippedBroadcastParameters)->toBe(2);
    expect($report->datasetIdChanged)->toBeFalse();
});

it('strips existing Broadcast rows when injecting even if stripping is off', function () {
    $json = '{"ScheduleData":{"Broadcast":[{"id":"OLD1"}],"Broadcast_Parameter":[{"broadcast_id":"OLD1"}]}}';

    [$output, $report] = rewriteJson($json, null, webhookInjection(), stripExisting: false);

    expect($report->strippedBroadcasts)->toBe(1);
    expect($report->strippedBroadcastParameters)->toBe(1);
    expect($output)->not->toContain('OLD1');
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData']['Broadcast'])->toHaveCount(1);
});

it('copies a file without a wrapper unchanged when only stripping is asked for', function () {
    $json = '{"Other":{"Broadcast":[{"id":"OLD"}]}}';

    [$output, $report] = rewriteJson($json);

    expect($output)->toBe($json);
    expect($report->wrapperFound)->toBeFalse();
    expect($report->strippedBroadcasts)->toBe(0);
});

function jsonIdentity(): InputReferenceIdentity
{
    return new InputReferenceIdentity('PSO Test Tools', 'Sent from PSO Test Tools (Load from File) by Ré "Q", environment TST, file a/b.json, 2026-10-16 13:40Z');
}

it('replaces an existing user_id and description where they stand, leaving every other byte as it was', function () {
    $before = "{\n  \"ScheduleData\": {\n    \"Input_Reference\": [\n      {\n        \"id\": \"7\",\n        \"user_id\" : \"IFS\",\n        \"dataset_id\": \"N\",\n        \"description\":\"old\"\n      }\n    ],\n";
    $rest = "    \"Activity\": [ {\"id\":\"A1\", \"description\" : \"keep me\", \"user_id\": \"x\"} ]\n  }\n}\n";

    [$output, $report] = rewriteJson($before.$rest, identity: jsonIdentity());

    $userId = json_encode('PSO Test Tools');
    $description = json_encode(jsonIdentity()->description, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($output)->toBe(str_replace(['"IFS"', '"old"'], [$userId, $description], $before).$rest);
    expect($report->inputReferencesMarked)->toBe(1);
    expect($report->identityFieldsReplaced)->toBe(2);
});

it('adds a missing user_id and description after dataset_id in the style of that member', function () {
    $json = "{\r\n  \"dsScheduleData\" : {\r\n    \"Input_Reference\" : {\r\n      \"id\" : \"7\",\r\n      \"dataset_id\" : \"N\",\r\n      \"input_type\" : \"LOAD\"\r\n    },\r\n    \"Resources\" : {\"id\":\"R1\"}\r\n  }\r\n}\r\n";

    [$output, $report] = rewriteJson($json, identity: jsonIdentity());

    $description = json_encode(jsonIdentity()->description, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $added = ",\r\n      \"user_id\" : \"PSO Test Tools\",\r\n      \"description\" : {$description}";

    expect($output)->toBe(str_replace('"dataset_id" : "N"', '"dataset_id" : "N"'.$added, $json));
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['dsScheduleData']['Input_Reference'])->toBe([
        'id' => '7',
        'dataset_id' => 'N',
        'user_id' => 'PSO Test Tools',
        'description' => jsonIdentity()->description,
        'input_type' => 'LOAD',
    ]);
    expect($report->inputReferencesMarked)->toBe(1);
    expect($report->identityFieldsReplaced)->toBe(0);
});

it('adds the identity as the last members when there is no dataset_id, and to an empty row', function () {
    [$output] = rewriteJson('{"ScheduleData":{"Input_Reference":[{"id":"1","input_type":"LOAD"},{}]}}', identity: jsonIdentity());

    $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData']['Input_Reference'];

    expect(array_keys($rows[0]))->toBe(['id', 'input_type', 'user_id', 'description']);
    expect($rows[1])->toBe(['user_id' => 'PSO Test Tools', 'description' => jsonIdentity()->description]);
    expect($output)->toStartWith('{"ScheduleData":{"Input_Reference":[{"id":"1","input_type":"LOAD","user_id":"PSO Test Tools","description":');
});

it('marks every Input_Reference row, replacing one value and adding the other', function () {
    $json = '{"ScheduleData":{"Input_Reference":[{"dataset_id":"A","user_id":{"odd":[1,2]}},{"description":"x","dataset_id":"B","id":"2"}],"Resources":[]}}';

    [$output, $report] = rewriteJson($json, 'NEW', null, true, jsonIdentity());

    $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData']['Input_Reference'];

    // The missing description goes in after dataset_id; user_id is replaced where it stands.
    expect($rows[0])->toBe(['dataset_id' => 'NEW', 'description' => jsonIdentity()->description, 'user_id' => 'PSO Test Tools']);
    expect($rows[1])->toBe(['description' => jsonIdentity()->description, 'dataset_id' => 'NEW', 'user_id' => 'PSO Test Tools', 'id' => '2']);
    expect($report->inputReferencesMarked)->toBe(2);
    expect($report->identityFieldsReplaced)->toBe(2);
    expect($report->datasetIds)->toBe(['A', 'B']);
    expect($report->datasetIdChanged)->toBeTrue();
});

it('marks Input_Reference alongside the injected Broadcast and leaves the rest of the file alone', function () {
    $resources = '"Resources" : [ { "id":"Ré", "description":"keep", "x":1.50 } ]';
    $json = "{\"ScheduleData\":{\"Input_Reference\":[{\"dataset_id\":\"N\"}],\"Broadcast\":[{\"id\":\"OLD\"}],{$resources}}}";

    [$output, $report] = rewriteJson($json, null, webhookInjection(), true, jsonIdentity());

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ScheduleData'];

    expect($output)->toContain($resources);
    expect($decoded['Input_Reference'][0]['user_id'])->toBe('PSO Test Tools');
    expect($decoded['Broadcast'][0]['id'])->toBe('WEBHOOK_COMPLETE');
    expect($report->inputReferencesMarked)->toBe(1);
    expect($report->strippedBroadcasts)->toBe(1);
});

it('marks nothing in a file with no Input_Reference', function () {
    $json = '{"ScheduleData":{"Resources":[{"id":"R1","user_id":"x"}]}}';

    [$output, $report] = rewriteJson($json, identity: jsonIdentity());

    expect($output)->toBe($json);
    expect($report->inputReferencesMarked)->toBe(0);
});

function shortJsonIdentity(string $description = 'D'): InputReferenceIdentity
{
    return new InputReferenceIdentity('PSO Test Tools', $description);
}

it('leaves user_id and description keys inside a nested object of Input_Reference alone', function () {
    $json = '{"ScheduleData":{"Input_Reference":[{"dataset_id":"N","extra":{"description":"inner","user_id":"inner"}}]}}';

    [$output, $report] = rewriteJson($json, identity: shortJsonIdentity());

    expect($output)->toBe('{"ScheduleData":{"Input_Reference":[{"dataset_id":"N","user_id":"PSO Test Tools","description":"D","extra":{"description":"inner","user_id":"inner"}}]}}');
    expect($report->identityFieldsReplaced)->toBe(0);
});

it('marks Input_Reference in a file with a UTF-8 BOM', function () {
    $json = "\xEF\xBB\xBF{\"ScheduleData\":{\"Input_Reference\":[{\"dataset_id\":\"N\"}]}}";

    [$output] = rewriteJson($json, identity: shortJsonIdentity());

    expect($output)->toBe("\xEF\xBB\xBF{\"ScheduleData\":{\"Input_Reference\":[{\"dataset_id\":\"N\",\"user_id\":\"PSO Test Tools\",\"description\":\"D\"}]}}");
});

it('adds after the last member and keeps the whitespace before the closing brace when there is no dataset_id', function () {
    $json = "{\"ScheduleData\":{\"Input_Reference\":[ { \"id\" : \"1\" \n } ]}}";

    [$output] = rewriteJson($json, identity: shortJsonIdentity());

    expect($output)->toBe("{\"ScheduleData\":{\"Input_Reference\":[ { \"id\" : \"1\", \"user_id\" : \"PSO Test Tools\", \"description\" : \"D\" \n } ]}}");
});

it('escapes a backslash and quotes in the description', function () {
    $json = '{"ScheduleData":{"Input_Reference":[{"description":"old"}]}}';

    [$output] = rewriteJson($json, identity: shortJsonIdentity('C:\\loads\\a "b".json'));

    expect($output)->toBe('{"ScheduleData":{"Input_Reference":[{"description":"C:\\\\loads\\\\a \\"b\\".json","user_id":"PSO Test Tools"}]}}');
    expect(json_decode($output, true)['ScheduleData']['Input_Reference'][0]['description'])->toBe('C:\\loads\\a "b".json');
});
