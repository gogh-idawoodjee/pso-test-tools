<?php

namespace App\Support\TrimExport\Readers;

use App\Support\TrimExport\Segment;
use Generator;
use RuntimeException;

/**
 * Cuts a dsScheduleData XML file into segments by scanning the markup just far enough to track element depth
 * (the scanner of XmlScheduleDataRewriter): every element just inside the root is a row of the table it is
 * named after. Bytes are never re-serialised, so a writer copies kept rows verbatim and both passes see the
 * same rows. Memory stays at one read chunk plus the longest row.
 */
class XmlTableReader implements TableReader
{
    private const array ROOT_NAMES = ['dsScheduleData', 'ScheduleData'];

    private const int READ_CHUNK_BYTES = 1048576;

    /** The longest single row, markup construct or tail held in memory; past it the file is truncated or damaged. */
    public const int MAX_SEGMENT_BYTES = 16 * 1048576;

    private const string NAME = '[A-Za-z_][\w.\-]*';

    public function segments(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The export could not be opened.');
        }

        try {
            $buffer = '';
            $endOfFile = false;
            $this->readChunk($handle, $buffer, $endOfFile);
            $start = substr($buffer, 0, 2);
            if ($start === "\xFF\xFE" || $start === "\xFE\xFF" || $start === "<\x00" || $start === "\x00<") {
                throw new RuntimeException('UTF-16 exports are not supported. Save the file as UTF-8.');
            }

            $consumed = 0; // bytes already cut from the front of the buffer
            $position = 0; // scan position in the buffer
            $segmentStart = 0; // first byte of the segment being collected
            $bodyStart = 0; // the '<' of the row being collected
            $depth = 0;
            $rootSeen = false;
            $table = null;

            while (true) {
                $tagStart = strpos($buffer, '<', $position);
                $tagEnd = false;

                // Nine bytes tell a comment and CDATA apart; at the end of the file, use what is there.
                if ($tagStart !== false && ($endOfFile || strlen($buffer) - $tagStart >= 9)) {
                    $tagEnd = $this->constructEnd($buffer, $tagStart);
                }

                if ($tagEnd === false) {
                    if ($endOfFile) {
                        break;
                    }

                    // Drop what has been handed out, keep the segment being collected, read on.
                    $resumeAt = $tagStart === false ? strlen($buffer) : $tagStart;
                    $buffer = substr($buffer, $segmentStart);
                    $consumed += $segmentStart;
                    $position = $resumeAt - $segmentStart;
                    $bodyStart -= $segmentStart;
                    $segmentStart = 0;
                    $this->guardSegmentLength(strlen($buffer));
                    $this->readChunk($handle, $buffer, $endOfFile);

                    continue;
                }

                $position = $tagEnd + 1;
                $marker = $buffer[$tagStart + 1];

                if ($marker === '!' || $marker === '?') {
                    // Comment, CDATA, processing instruction, DOCTYPE or declaration: part of the current segment.
                    continue;
                }

                if ($marker === '/') {
                    $depth--;

                    if ($depth === 1 && $table !== null) {
                        yield $this->row($table, $buffer, $segmentStart, $bodyStart, $position, $consumed);
                        $segmentStart = $position;
                        $table = null;
                    } elseif ($depth === 0) {
                        yield $this->tail($handle, substr($buffer, $segmentStart), $endOfFile);

                        return;
                    }

                    continue;
                }

                $selfClosing = $buffer[$tagEnd - 1] === '/';
                $localName = $this->localName($buffer, $tagStart);

                if ($depth === 0) {
                    if (! in_array($localName, self::ROOT_NAMES, true)) {
                        throw new RuntimeException("This file is a <{$localName}> file, not PSO schedule data (<dsScheduleData>).");
                    }

                    $rootSeen = true;
                    yield new Segment(Segment::HEAD, body: substr($buffer, $segmentStart, $position - $segmentStart), offset: $consumed + $position);
                    $segmentStart = $position;

                    if ($selfClosing) {
                        yield $this->tail($handle, substr($buffer, $segmentStart), $endOfFile);

                        return;
                    }

                    $depth = 1;

                    continue;
                }

                if ($depth === 1) {
                    $table = $localName;
                    $bodyStart = $tagStart;

                    if ($selfClosing) {
                        yield $this->row($table, $buffer, $segmentStart, $bodyStart, $position, $consumed);
                        $segmentStart = $position;
                        $table = null;

                        continue;
                    }

                    $depth = 2;

                    continue;
                }

                if (! $selfClosing) {
                    $depth++;
                }
            }

            throw new RuntimeException($rootSeen
                ? 'The export ended inside dsScheduleData. It looks truncated.'
                : 'This file has no dsScheduleData root, so it is not PSO schedule data.');
        } finally {
            fclose($handle);
        }
    }

    public function fieldsOf(string $body, array $names): array
    {
        $wanted = array_flip($names);
        $fields = [];

        // Rows are flat: each field is a child element holding text. An empty or self-closing one has no value.
        preg_match_all('#<(?:'.self::NAME.':)?('.self::NAME.')(?:\s[^>]*)?>([^<]*)</#', $body, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $name, $text]) {
            if (! isset($wanted[$name]) || isset($fields[$name])) {
                continue;
            }

            $value = trim(str_contains($text, '&') ? html_entity_decode($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') : $text);
            if ($value !== '') {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }

    public function replaceField(string $body, string $field, string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $name = '((?:'.self::NAME.':)?'.preg_quote($field, '#').')';

        $replaced = preg_replace_callback(
            '#(<'.$name.'(?:\s[^>]*)?>)[^<]*(</\2\s*>)#',
            static fn (array $match): string => $match[1].$escaped.$match[3],
            $body, 1, $count,
        );
        if ($count === 1) {
            return (string) $replaced;
        }

        // <datetime/>: open it up to take the value.
        $replaced = preg_replace_callback(
            '#<'.$name.'(\s[^>]*)?/>#',
            static fn (array $match): string => '<'.$match[1].($match[2] ?? '').'>'.$escaped.'</'.$match[1].'>',
            $body, 1, $count,
        );

        return $count === 1 ? (string) $replaced : $body;
    }

    private function row(string $table, string $buffer, int $segmentStart, int $bodyStart, int $end, int $consumed): Segment
    {
        return new Segment(
            Segment::ROW,
            $table,
            substr($buffer, $segmentStart, $bodyStart - $segmentStart),
            substr($buffer, $bodyStart, $end - $bodyStart),
            $consumed + $end,
        );
    }

    /** @param resource $handle */
    private function tail($handle, string $rest, bool $endOfFile): Segment
    {
        while (! $endOfFile) {
            $this->guardSegmentLength(strlen($rest));
            $this->readChunk($handle, $rest, $endOfFile);
        }

        return new Segment(Segment::TAIL, body: $rest, offset: (int) ftell($handle));
    }

    private function guardSegmentLength(int $bytes): void
    {
        if ($bytes > self::MAX_SEGMENT_BYTES) {
            throw new RuntimeException('The export has a row or markup more than '.(self::MAX_SEGMENT_BYTES / 1048576).' MB long that never ends. It looks truncated or damaged.');
        }
    }

    private function localName(string $buffer, int $tagStart): string
    {
        $name = substr($buffer, $tagStart + 1, strcspn($buffer, " \t\r\n/>", $tagStart + 1));
        $colon = strpos($name, ':');

        return $colon === false ? $name : substr($name, $colon + 1);
    }

    /** @param resource $handle */
    private function readChunk($handle, string &$buffer, bool &$endOfFile): void
    {
        $chunk = fread($handle, self::READ_CHUNK_BYTES);
        if ($chunk === false) {
            throw new RuntimeException('The export could not be read.');
        }

        $buffer .= $chunk;
        $endOfFile = $chunk === '' || feof($handle);
    }

    /** The offset of the last byte of the markup construct starting at the given '<', or false when it does not end inside the buffer. */
    private function constructEnd(string $buffer, int $tagStart): int|false
    {
        if ($tagStart + 1 >= strlen($buffer)) {
            return false;
        }

        $next = $buffer[$tagStart + 1];

        if ($next === '!') {
            if (substr_compare($buffer, '<!--', $tagStart, 4) === 0) {
                $end = strpos($buffer, '-->', $tagStart + 4);

                return $end === false ? false : $end + 2;
            }

            if (substr_compare($buffer, '<![CDATA[', $tagStart, 9) === 0) {
                $end = strpos($buffer, ']]>', $tagStart + 9);

                return $end === false ? false : $end + 2;
            }

            // DOCTYPE: skip quoted strings and the internal subset.
            return $this->quotedEnd($buffer, $tagStart + 2, true);
        }

        if ($next === '?') {
            $end = strpos($buffer, '?>', $tagStart + 2);

            return $end === false ? false : $end + 1;
        }

        if ($next === '/') {
            return strpos($buffer, '>', $tagStart + 2);
        }

        $end = strpos($buffer, '>', $tagStart + 1);
        if ($end === false) {
            return false;
        }

        // Fast path: no quoted attribute value that could hide a '>'.
        if (strcspn($buffer, "\"'", $tagStart + 1, $end - $tagStart - 1) === $end - $tagStart - 1) {
            return $end;
        }

        return $this->quotedEnd($buffer, $tagStart + 1, false);
    }

    private function quotedEnd(string $buffer, int $from, bool $allowSubset): int|false
    {
        $length = strlen($buffer);
        $subsetDepth = 0;
        $index = $from;

        while (true) {
            $index += strcspn($buffer, $allowSubset ? "\"'>[]" : "\"'>", $index);
            if ($index >= $length) {
                return false;
            }

            $character = $buffer[$index];
            if ($character === '>' && $subsetDepth === 0) {
                return $index;
            }

            if ($character === '"' || $character === "'") {
                $close = strpos($buffer, $character, $index + 1);
                if ($close === false) {
                    return false;
                }
                $index = $close + 1;

                continue;
            }

            if ($character === '[') {
                $subsetDepth++;
            } elseif ($character === ']') {
                $subsetDepth--;
            }

            $index++;
        }
    }
}
