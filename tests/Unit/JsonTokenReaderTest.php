<?php

use App\Support\ScheduleData\JsonTokenReader;

function jsonTokens(string $json): array
{
    $handle = fopen('php://memory', 'w+b');
    fwrite($handle, $json);
    rewind($handle);

    try {
        return iterator_to_array(app(JsonTokenReader::class)->tokens($handle), false);
    } finally {
        fclose($handle);
    }
}

it('splits a document into raw tokens', function () {
    expect(jsonTokens('{"a":"b"}'))->toBe(['{', '"a"', ':', '"b"', '}']);
    expect(jsonTokens('[1, 2.50,true ,null]'))->toBe(['[', '1', ',', ' ', '2.50', ',', 'true', ' ', ',', 'null', ']']);
});

it('reproduces the input exactly, including whitespace, escapes and a BOM', function () {
    $json = "\xEF\xBB\xBF{ \"a\" : [1, 2.50, true, null, \"x\\\"y\\\\\\u00e9\"],\r\n\t\"b\":{} }\n";

    expect(implode('', jsonTokens($json)))->toBe($json);
});

it('keeps a string that spans several chunks as one token', function () {
    $value = str_repeat('ab"c\\d', 30000);
    $json = json_encode(['k' => $value]);

    $tokens = jsonTokens($json);

    expect(implode('', $tokens))->toBe($json);
    expect(json_decode($tokens[3]))->toBe($value);
});

it('does not split an escape across the chunk boundary', function () {
    // Byte 65535 is the backslash, the escaped quote starts the next chunk.
    $json = '{"k":"'.str_repeat('a', 65529).'\\"'.'tail"}';

    expect(strlen('{"k":"'.str_repeat('a', 65529)))->toBe(65535);

    $tokens = jsonTokens($json);

    expect(implode('', $tokens))->toBe($json);
    expect(json_decode($tokens[3]))->toBe(str_repeat('a', 65529).'"tail');
});

it('keeps a number that spans the chunk boundary as one token', function () {
    $json = '{"k":'.str_repeat(' ', 65536 - 5 - 3).'12345678}';

    $tokens = jsonTokens($json);

    expect(implode('', $tokens))->toBe($json);
    expect($tokens)->toContain('12345678');
});

it('throws on an unterminated string', function () {
    expect(static fn () => jsonTokens('{"a":"never closed'))->toThrow(RuntimeException::class);
});
