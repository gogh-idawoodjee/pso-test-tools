<?php

use App\Support\TrimExport\Readers\JsonTableReader;
use App\Support\TrimExport\Readers\XmlTableReader;
use App\Support\TrimExport\Segment;
use App\Support\TrimExport\Writers\JsonTableWriter;
use App\Support\TrimExport\Writers\XmlTableWriter;

beforeEach(function () {
    $this->target = sys_get_temp_dir().'/trim-out-'.uniqid();
});

afterEach(function () {
    @unlink($this->target);
    @unlink($this->target.'.part');
});

it('copies every byte of an xml export when nothing is dropped', function () {
    $xml = "\xEF\xBB\xBF<?xml version=\"1.0\"?>\r\n<ns:dsScheduleData xmlns:ns=\"urn:x\">\r\n  <ns:Activity a=\"1 > 0\"><ns:id>A1</ns:id><![CDATA[<x>]]></ns:Activity>\r\n  <!-- note -->\r\n  <ns:Plan/>\r\n</ns:dsScheduleData>\r\n";
    $source = trimFixtureFile($xml);

    $bytes = trimCopy(app(XmlTableReader::class), new XmlTableWriter, $source, $this->target);

    expect(file_get_contents($this->target))->toBe($xml)->and($bytes)->toBe(strlen($xml));
});

it('copies every byte of a json export when nothing is dropped', function () {
    $json = "{\n  \"dsScheduleData\": {\n    \"Input_Reference\": {\"datetime\": \"x\"},\n    \"Activity\": [\n      {\"id\": \"A1\"},\n      {\"id\": \"A2\"}\n    ],\n    \"Resources\": [],\n    \"Odd\": null\n  }\n}\n";
    $source = trimFixtureFile($json, 'json');

    trimCopy(app(JsonTableReader::class), new JsonTableWriter, $source, $this->target);

    expect(file_get_contents($this->target))->toBe($json);
});

it('drops an xml row with the whitespace before it, and a whole table', function () {
    $source = trimXml([
        'Activity' => [['id' => 'A1'], ['id' => 'A2'], ['id' => 'A3']],
        'Plan' => [['id' => 'P1']],
    ]);
    $reader = app(XmlTableReader::class);

    trimCopy($reader, new XmlTableWriter, $source, $this->target,
        keepRow: static fn (Segment $segment): bool => ($reader->fieldsOf($segment->body, ['id'])['id'] ?? null) !== 'A2',
        keepTable: static fn (string $table): bool => $table !== 'Plan');

    expect(trimmedRows($this->target, 'xml'))->toBe(['Activity' => [['id' => 'A1'], ['id' => 'A3']]])
        ->and(file_get_contents($this->target))->not->toContain("\n  \n");
});

it('writes valid JSON when the first row or the first table is dropped', function () {
    $source = trimJson([
        'Input_Reference' => [['datetime' => 'x']],
        'Activity' => [['id' => 'A1'], ['id' => 'A2'], ['id' => 'A3']],
    ]);
    $reader = app(JsonTableReader::class);

    trimCopy($reader, new JsonTableWriter, $source, $this->target,
        keepRow: static fn (Segment $segment): bool => ($reader->fieldsOf($segment->body, ['id'])['id'] ?? null) !== 'A1',
        keepTable: static fn (string $table): bool => $table !== 'Input_Reference');

    expect(json_decode((string) file_get_contents($this->target), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['dsScheduleData' => ['Activity' => [['id' => 'A2'], ['id' => 'A3']]]]);
});

it('keeps a one-row table an object, writes [] when its row goes, and keeps a list a list', function () {
    $source = trimJson([
        'Input_Reference' => [['datetime' => 'x']],
        'Activity' => [['id' => 'A1'], ['id' => 'A2']],
    ], singleObjects: ['Input_Reference']);
    $reader = app(JsonTableReader::class);

    trimCopy($reader, new JsonTableWriter, $source, $this->target,
        keepRow: static fn (Segment $segment): bool => ($reader->fieldsOf($segment->body, ['id'])['id'] ?? null) !== 'A2');
    $kept = json_decode((string) file_get_contents($this->target), true);

    expect($kept['dsScheduleData']['Input_Reference'])->toBe(['datetime' => 'x'])
        ->and($kept['dsScheduleData']['Activity'])->toBe([['id' => 'A1']]);

    trimCopy($reader, new JsonTableWriter, $source, $this->target,
        keepRow: static fn (Segment $segment): bool => $segment->table !== 'Input_Reference');

    expect((string) file_get_contents($this->target))->toContain('"Input_Reference": []')
        ->and(json_decode((string) file_get_contents($this->target), true)['dsScheduleData']['Input_Reference'])->toBe([]);
});

it('leaves no file behind until the copy is complete, and none after an abort', function () {
    $writer = new XmlTableWriter;
    $writer->open($this->target);
    $writer->write(new Segment(Segment::HEAD, body: str_repeat('x', 300000)), true);

    expect(file_exists($this->target))->toBeFalse()
        ->and(file_exists($this->target.'.part'))->toBeTrue();

    $writer->abort();

    expect(file_exists($this->target))->toBeFalse()
        ->and(file_exists($this->target.'.part'))->toBeFalse();
});

it('writes valid JSON whichever rows of a table are dropped', function (array $dropped, array $expected) {
    $source = trimJson(['Activity' => [['id' => 'A1'], ['id' => 'A2'], ['id' => 'A3']], 'Plan' => [['id' => 'P1']]]);
    $reader = app(JsonTableReader::class);

    trimCopy($reader, new JsonTableWriter, $source, $this->target,
        keepRow: static fn (Segment $segment): bool => $segment->table !== 'Activity'
            || ! in_array($reader->fieldsOf($segment->body, ['id'])['id'] ?? null, $dropped, true));

    expect(trimmedRows($this->target, 'json'))->toBe($expected + ['Plan' => [['id' => 'P1']]]);
})->with([
    'first' => [['A1'], ['Activity' => [['id' => 'A2'], ['id' => 'A3']]]],
    'middle' => [['A2'], ['Activity' => [['id' => 'A1'], ['id' => 'A3']]]],
    'last' => [['A3'], ['Activity' => [['id' => 'A1'], ['id' => 'A2']]]],
    'every row' => [['A1', 'A2', 'A3'], []],
]);
