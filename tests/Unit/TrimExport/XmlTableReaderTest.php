<?php

use App\Support\TrimExport\Readers\XmlTableReader;

it('cuts an export into head, rows and tail and gives it back byte for byte', function () {
    $xml = "\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n<!-- exported -->\r\n<ns:dsScheduleData xmlns:ns=\"http://360Scheduling.com/Schema/dsScheduleData.xsd\">\r\n"
        ."  <ns:Input_Reference><ns:datetime>2026-10-04T08:00:00</ns:datetime></ns:Input_Reference>\r\n"
        ."  <ns:Activity note=\"a > b\"><ns:id>A1</ns:id><ns:description><![CDATA[<b>keep</b>]]></ns:description></ns:Activity>\r\n"
        ."  <ns:Activity/>\r\n"
        ."  <!-- between rows -->\r\n"
        ."  <ns:Plan><ns:id>P1</ns:id></ns:Plan>\r\n"
        ."</ns:dsScheduleData>\r\n";

    $segments = iterator_to_array(app(XmlTableReader::class)->segments(trimFixtureFile($xml)), false);

    expect(joinSegments($segments))->toBe($xml)
        ->and(segmentShape($segments))->toBe(['head', 'row Input_Reference', 'row Activity', 'row Activity', 'row Plan', 'tail'])
        ->and($segments[0]->body)->toEndWith('dsScheduleData.xsd">')
        ->and($segments[2]->prefix)->toBe("\r\n  ")
        ->and($segments[2]->body)->toStartWith('<ns:Activity note="a > b">')->toEndWith('</ns:Activity>')
        ->and($segments[3]->body)->toBe('<ns:Activity/>')
        ->and($segments[4]->prefix)->toBe("\r\n  <!-- between rows -->\r\n  ")
        ->and($segments[5]->body)->toBe("\r\n</ns:dsScheduleData>\r\n")
        ->and($segments[5]->offset)->toBe(strlen($xml));
});

it('reads an empty root as head and tail', function () {
    $xml = '<?xml version="1.0"?><dsScheduleData/>';
    $segments = iterator_to_array(app(XmlTableReader::class)->segments(trimFixtureFile($xml)), false);

    expect(segmentShape($segments))->toBe(['head', 'tail'])->and(joinSegments($segments))->toBe($xml);
});

it('reads across read chunks without losing or splitting a row', function () {
    $rows = '';
    for ($index = 0; $index < 30000; $index++) {
        $rows .= "\n  <Activity><id>A{$index}</id><description>".str_repeat('x', 20).'</description></Activity>';
    }
    $xml = '<dsScheduleData>'.$rows."\n</dsScheduleData>";
    $reader = app(XmlTableReader::class);

    $segments = iterator_to_array($reader->segments(trimFixtureFile($xml)), false);

    expect(strlen($xml))->toBeGreaterThan(1048576)
        ->and($segments)->toHaveCount(30002)
        ->and(joinSegments($segments))->toBe($xml)
        ->and($reader->fieldsOf($segments[29999]->body, ['id']))->toBe(['id' => 'A29998']);
});

it('reads the fields of a row, decoding entities and skipping empty ones', function () {
    $body = '<ns:Activity><ns:id> A&amp;1 </ns:id><ns:location_id/><ns:activity_type_id></ns:activity_type_id><ns:description>x</ns:description></ns:Activity>';

    expect(app(XmlTableReader::class)->fieldsOf($body, ['id', 'location_id', 'activity_type_id']))->toBe(['id' => 'A&1']);
});

it('replaces one field of a row, opening an empty element to take it', function () {
    $reader = app(XmlTableReader::class);

    expect($reader->replaceField('<Input_Reference><id>x</id><datetime>2026-10-04T08:00:00</datetime></Input_Reference>', 'datetime', '2026-10-05T06:00:00'))
        ->toBe('<Input_Reference><id>x</id><datetime>2026-10-05T06:00:00</datetime></Input_Reference>')
        ->and($reader->replaceField('<ns:Input_Reference><ns:datetime/></ns:Input_Reference>', 'datetime', 'A&B'))
        ->toBe('<ns:Input_Reference><ns:datetime>A&amp;B</ns:datetime></ns:Input_Reference>')
        ->and($reader->replaceField('<Input_Reference><input_reference_datetime>x</input_reference_datetime></Input_Reference>', 'datetime', 'y'))
        ->toBe('<Input_Reference><input_reference_datetime>x</input_reference_datetime></Input_Reference>');
});

it('refuses a file that is not schedule data, a truncated one and UTF-16', function () {
    $reader = app(XmlTableReader::class);

    expect(fn () => iterator_to_array($reader->segments(trimFixtureFile('<DsSystemData><X/></DsSystemData>'))))
        ->toThrow(RuntimeException::class, 'not PSO schedule data');
    expect(fn () => iterator_to_array($reader->segments(trimFixtureFile('<dsScheduleData><Activity><id>1</id>'))))
        ->toThrow(RuntimeException::class, 'looks truncated');
    expect(fn () => iterator_to_array($reader->segments(trimFixtureFile("\xFF\xFE<\x00d\x00"))))
        ->toThrow(RuntimeException::class, 'UTF-16');
});

it('refuses an attribute that never closes once it passes the cap, without holding the file', function () {
    $path = trimFixtureFile('<dsScheduleData><Activity note="');
    $handle = fopen($path, 'ab');
    $chunk = str_repeat('x > ', 262144);
    for ($written = 0; $written <= XmlTableReader::MAX_SEGMENT_BYTES + 16777216; $written += strlen($chunk)) {
        fwrite($handle, $chunk);
    }
    fclose($handle);
    unset($chunk);

    memory_reset_peak_usage();
    $before = memory_get_usage(true);

    expect(fn () => iterator_to_array(app(XmlTableReader::class)->segments($path)))
        ->toThrow(RuntimeException::class, 'looks truncated or damaged')
        ->and(memory_get_peak_usage(true) - $before)->toBeLessThan(XmlTableReader::MAX_SEGMENT_BYTES + 8388608)
        ->and(filesize($path))->toBeGreaterThan(XmlTableReader::MAX_SEGMENT_BYTES + 16777216);
});
