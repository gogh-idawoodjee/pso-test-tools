<?php

namespace App\Support\ScheduleData;

use Generator;
use RuntimeException;

/**
 * Yields the raw tokens of a JSON document from a file handle, 64 KB at a
 * time, so a 200 MB schedule data file is never held in memory. Tokens are
 * the six structural characters one at a time, whole strings (quotes and
 * escapes included), runs of literal characters (numbers, true, false, null)
 * and runs of whitespace. Concatenating every token reproduces the input.
 *
 * It does not validate: it only needs to find where strings end, so that
 * braces inside strings are never mistaken for structure.
 */
class JsonTokenReader
{
    private const int CHUNK_BYTES = 65536;

    private const string WHITESPACE = " \t\r\n";

    private const string STRUCTURAL = '{}[]:,';

    /**
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    public function tokens($handle): Generator
    {
        $buffer = '';
        $position = 0;
        $endOfFile = false;

        while (true) {
            if ($position >= strlen($buffer)) {
                if ($endOfFile) {
                    return;
                }

                $buffer = $this->readChunk($handle, $endOfFile);
                $position = 0;

                if ($buffer === '') {
                    return;
                }
            }

            $character = $buffer[$position];

            if (str_contains(self::WHITESPACE, $character)) {
                $length = strspn($buffer, self::WHITESPACE, $position);
                $token = substr($buffer, $position, $length);
                $position += $length;

                yield $token;

                continue;
            }

            if (str_contains(self::STRUCTURAL, $character)) {
                $position++;

                yield $character;

                continue;
            }

            if ($character === '"') {
                yield $this->readString($handle, $buffer, $position, $endOfFile);

                continue;
            }

            yield $this->readLiteral($handle, $buffer, $position, $endOfFile);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function readChunk($handle, bool &$endOfFile): string
    {
        $chunk = fread($handle, self::CHUNK_BYTES);

        if ($chunk === false) {
            throw new RuntimeException('The JSON file could not be read.');
        }

        if ($chunk === '' || feof($handle)) {
            $endOfFile = true;
        }

        return $chunk;
    }

    /**
     * @param  resource  $handle
     */
    private function readString($handle, string &$buffer, int &$position, bool &$endOfFile): string
    {
        $token = '"';
        $position++;

        while (true) {
            if ($position >= strlen($buffer)) {
                $this->refill($handle, $buffer, $position, $endOfFile);
            }

            $length = strcspn($buffer, '"\\', $position);
            $token .= substr($buffer, $position, $length);
            $position += $length;

            if ($position >= strlen($buffer)) {
                continue;
            }

            $character = $buffer[$position];
            $token .= $character;
            $position++;

            if ($character === '"') {
                return $token;
            }

            // A backslash: the next byte belongs to the escape, in whichever chunk it is.
            if ($position >= strlen($buffer)) {
                $this->refill($handle, $buffer, $position, $endOfFile);
            }

            $token .= $buffer[$position];
            $position++;
        }
    }

    /**
     * @param  resource  $handle
     */
    private function readLiteral($handle, string &$buffer, int &$position, bool &$endOfFile): string
    {
        $token = '';

        while (true) {
            $length = strcspn($buffer, self::WHITESPACE.self::STRUCTURAL.'"', $position);
            $token .= substr($buffer, $position, $length);
            $position += $length;

            if ($position < strlen($buffer) || $endOfFile) {
                return $token;
            }

            $buffer = $this->readChunk($handle, $endOfFile);
            $position = 0;

            if ($buffer === '') {
                return $token;
            }
        }
    }

    /**
     * Loads the next chunk in the middle of a string; running out of input
     * here means the string never closed.
     *
     * @param  resource  $handle
     */
    private function refill($handle, string &$buffer, int &$position, bool &$endOfFile): void
    {
        if ($endOfFile) {
            throw new RuntimeException('Unterminated string in the JSON file.');
        }

        $buffer = $this->readChunk($handle, $endOfFile);
        $position = 0;

        if ($buffer === '') {
            throw new RuntimeException('Unterminated string in the JSON file.');
        }
    }
}
