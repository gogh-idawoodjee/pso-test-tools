<?php

namespace App\Support\TrimExport\Readers;

use App\Support\ScheduleData\JsonTokenReader;
use App\Support\TrimExport\Segment;
use Generator;
use RuntimeException;

/**
 * Cuts a schedule data JSON file into segments with JsonTokenReader: each member of the dsScheduleData (or
 * 6.15+ ScheduleData) wrapper is a table, each object in its list a row. Older files write a one-row table as
 * a plain object; that row comes as a single-object table. Only one row is ever held.
 */
class JsonTableReader implements TableReader
{
    private const array WRAPPER_KEYS = ['dsScheduleData', 'ScheduleData'];

    /** How far into the file the wrapper is looked for before the file is refused, so memory stays flat. */
    public const int MAX_HEAD_BYTES = 16 * 1048576;

    public function __construct(private JsonTokenReader $tokenReader) {}

    public function segments(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The export could not be opened.');
        }

        try {
            $offset = 0;
            $state = 'head'; // head, key, colon, value, rows, row, tail
            $head = '';
            $depth = 0;
            $previous = '';
            $wrapperPending = false;
            $prefix = ''; // whitespace and commas before the next table or row
            $open = ''; // the table's key, colon and the whitespace before its value
            $table = '';
            $body = '';
            $rowDepth = 0;
            $single = false;
            $tail = '';

            foreach ($this->tokenReader->tokens($handle) as $token) {
                $offset += strlen($token);
                $whitespace = strspn($token[0], " \t\r\n") === 1;

                if ($state === 'head') {
                    $head .= $token;
                    if (strlen($head) > self::MAX_HEAD_BYTES) {
                        throw new RuntimeException('This file has no dsScheduleData wrapper in its first '.(self::MAX_HEAD_BYTES / 1048576).' MB, so it is not PSO schedule data.');
                    }
                    if ($whitespace) {
                        continue;
                    }

                    $before = $previous;
                    $previous = $token;

                    if ($wrapperPending && $token !== ':' && $token !== '{') {
                        $wrapperPending = false;
                    }

                    if ($token === '{' && $wrapperPending && $depth === 1) {
                        yield new Segment(Segment::HEAD, body: $head, offset: $offset);
                        $state = 'key';

                        continue;
                    }

                    if ($token === '{' || $token === '[') {
                        $depth++;
                    } elseif ($token === '}' || $token === ']') {
                        $depth--;
                    } elseif ($token[0] === '"' && $depth === 1 && ($before === '{' || $before === ',')) {
                        $wrapperPending = in_array(json_decode($token), self::WRAPPER_KEYS, true);
                    }

                    continue;
                }

                if ($state === 'key') {
                    if ($whitespace || $token === ',') {
                        $prefix .= $token;

                        continue;
                    }

                    if ($token === '}') {
                        $tail = $prefix.'}';
                        $prefix = '';
                        $state = 'tail';

                        continue;
                    }

                    $decoded = json_decode($token);
                    $table = is_string($decoded) ? $decoded : '';
                    $open = $token;
                    $state = 'colon';

                    continue;
                }

                if ($state === 'colon') {
                    $open .= $token;
                    if ($token === ':') {
                        $state = 'value';
                    }

                    continue;
                }

                if ($state === 'value') {
                    if ($whitespace) {
                        $open .= $token;

                        continue;
                    }

                    if ($token === '[') {
                        yield new Segment(Segment::TABLE_OPEN, $table, $prefix, $open.'[', $offset);
                        $prefix = '';
                        $state = 'rows';

                        continue;
                    }

                    if ($token === '{') {
                        yield new Segment(Segment::TABLE_OPEN, $table, $prefix, $open, $offset, singleObject: true);
                        $prefix = '';
                        $body = '{';
                        $rowDepth = 1;
                        $single = true;
                        $state = 'row';

                        continue;
                    }

                    // A scalar value (null): a table with no rows, copied whole.
                    yield new Segment(Segment::TABLE_OPEN, $table, $prefix, $open.$token, $offset, scalar: true);
                    yield new Segment(Segment::TABLE_CLOSE, $table, offset: $offset, scalar: true);
                    $prefix = '';
                    $state = 'key';

                    continue;
                }

                if ($state === 'rows') {
                    if ($whitespace || $token === ',') {
                        $prefix .= $token;

                        continue;
                    }

                    if ($token === ']') {
                        yield new Segment(Segment::TABLE_CLOSE, $table, $prefix, ']', $offset);
                        $prefix = '';
                        $state = 'key';

                        continue;
                    }

                    if ($token === '{' || $token === '[') {
                        $body = $token;
                        $rowDepth = 1;
                        $single = false;
                        $state = 'row';

                        continue;
                    }

                    // A bare value in a table's list: a row of its own.
                    yield new Segment(Segment::ROW, $table, $prefix, $token, $offset);
                    $prefix = '';

                    continue;
                }

                if ($state === 'row') {
                    $body .= $token;
                    if ($token === '{' || $token === '[') {
                        $rowDepth++;
                    } elseif ($token === '}' || $token === ']') {
                        $rowDepth--;
                    }

                    if ($rowDepth > 0) {
                        continue;
                    }

                    yield new Segment(Segment::ROW, $table, $prefix, $body, $offset);
                    $prefix = '';
                    $body = '';

                    if ($single) {
                        yield new Segment(Segment::TABLE_CLOSE, $table, offset: $offset, singleObject: true);
                        $single = false;
                        $state = 'key';
                    } else {
                        $state = 'rows';
                    }

                    continue;
                }

                $tail .= $token;
            }

            if ($state !== 'tail') {
                throw new RuntimeException($state === 'head'
                    ? 'This file has no dsScheduleData wrapper, so it is not PSO schedule data.'
                    : 'The export ended inside dsScheduleData. It looks truncated.');
            }

            yield new Segment(Segment::TAIL, body: $tail, offset: $offset);
        } finally {
            fclose($handle);
        }
    }

    public function fieldsOf(string $body, array $names): array
    {
        $row = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (! is_array($row)) {
            return [];
        }

        $fields = [];
        foreach ($names as $name) {
            $value = $row[$name] ?? null;
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            if (is_string($value) || is_int($value) || is_float($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $fields[$name] = $text;
                }
            }
        }

        return $fields;
    }

    public function replaceField(string $body, string $field, string $value): string
    {
        $replaced = preg_replace_callback(
            '/("'.preg_quote($field, '/').'"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|null|true|false|-?\d[\d.eE+\-]*)/',
            static fn (array $match): string => $match[1].json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $body, 1, $count,
        );

        return $count === 1 ? (string) $replaced : $body;
    }
}
