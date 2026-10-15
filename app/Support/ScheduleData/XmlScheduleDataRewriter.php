<?php

namespace App\Support\ScheduleData;

use DOMDocument;
use RuntimeException;

/**
 * Copies a dsScheduleData (or ScheduleData) XML file as raw bytes, scanning
 * the markup just far enough to track element depth. Only three things change:
 *
 * - the text of each `dataset_id` directly inside a root-level
 *   `Input_Reference` (when an override is given; its tags are kept, an
 *   empty `<dataset_id/>` becomes `<dataset_id>NEW</dataset_id>`);
 * - root-level `Broadcast` and `Broadcast_Parameter` elements, removed with
 *   the whitespace-only text just before each, but only when a Broadcast is
 *   injected (as in the script);
 * - the injected Broadcast and Broadcast_Parameter elements, appended as the
 *   last children of the root with the root's own prefix (so in the root's
 *   namespace, as the script does) and the indentation of its first child.
 *
 * Every other byte is copied verbatim: a BOM, the XML declaration, DOCTYPE,
 * comments, processing instructions, CDATA, entity and character references,
 * whitespace and line endings, attribute quoting and namespace prefixes.
 * Written text is escaped with non-ASCII as character references, so it is
 * valid whatever encoding the declaration names (UTF-16 files are refused).
 *
 * This does not validate the document: well-formedness beyond tag nesting
 * depth is left to PSO. A file with no schedule data root and nothing to
 * inject is copied unchanged. Memory stays at about one read chunk plus the
 * longest single tag. Any failure part way through may leave a partial
 * destination file behind; the caller deletes it.
 */
class XmlScheduleDataRewriter implements ScheduleDataRewriter
{
    private const array ROOT_NAMES = ['dsScheduleData', 'ScheduleData'];

    private const array STRIPPED_ENTITIES = ['Broadcast', 'Broadcast_Parameter'];

    private const int READ_CHUNK_BYTES = 1048576;

    private const string NO_ROOT_MESSAGE = "Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper in the file - can't inject a Broadcast.";

    /** @var resource */
    private $source;

    /** @var resource */
    private $destination;

    private string $buffer = '';

    private bool $endOfFile = false;

    private int $bytesWritten = 0;

    public function rewrite(
        string $sourcePath,
        string $destinationPath,
        ?string $datasetIdOverride,
        ?BroadcastInjection $injection,
    ): RewriteReport {
        $escapedOverride = $datasetIdOverride === null ? null : $this->escapeText($datasetIdOverride, rejectInvalid: true);

        $source = @fopen($sourcePath, 'rb');

        if ($source === false) {
            throw new RuntimeException("The schedule data file could not be opened: {$sourcePath}");
        }

        $sourceRealPath = realpath($sourcePath);

        if ($sourceRealPath !== false && $sourceRealPath === realpath($destinationPath)) {
            fclose($source);

            throw new RuntimeException('The rewritten schedule data cannot be written over the original file.');
        }

        $destination = @fopen($destinationPath, 'wb');

        if ($destination === false) {
            fclose($source);

            throw new RuntimeException("The rewritten schedule data could not be opened for writing: {$destinationPath}");
        }

        $this->source = $source;
        $this->destination = $destination;
        $this->buffer = '';
        $this->endOfFile = false;
        $this->bytesWritten = 0;

        try {
            return $this->copy($datasetIdOverride, $escapedOverride, $injection);
        } finally {
            fclose($source);
            fclose($destination);
            $this->buffer = '';
        }
    }

    private function copy(?string $datasetIdOverride, ?string $escapedOverride, ?BroadcastInjection $injection): RewriteReport
    {
        $this->readChunk();

        if ($this->looksLikeUtf16()) {
            if ($injection !== null || $datasetIdOverride !== null) {
                throw new RuntimeException('UTF-16 schedule data files are not supported - save the file as UTF-8.');
            }

            return $this->copyRest(0);
        }

        $position = 0; // scan position in the buffer
        $copyFrom = 0; // first buffer byte not yet written (or skipped while stripping)
        $textStart = 0; // end of the last markup construct: start of the current text run
        $depth = 0; // open elements before the current tag
        $rootSeen = false;
        $rootClosed = false;
        $rootPrefix = '';
        $childSeparator = null;
        $stripping = false;
        $stripDepth = 0;
        $strippedName = '';
        $inInputReference = false;
        $datasetTagStart = null; // buffer offset of the open dataset_id start tag
        $datasetInnerStart = 0;
        $datasetIds = [];
        $datasetIdChanged = false;
        $strippedBroadcasts = 0;
        $strippedParameters = 0;

        while (true) {
            $tagStart = strpos($this->buffer, '<', $position);
            $tagEnd = false;

            // Nine bytes tells a comment and CDATA apart; at the end of the file, use what is there.
            if ($tagStart !== false && ($this->endOfFile || strlen($this->buffer) - $tagStart >= 9)) {
                $tagEnd = $this->findConstructEnd($tagStart);
            }

            if ($tagEnd === false) {
                if ($this->endOfFile) {
                    break;
                }

                // Keep what a later edit still needs, flush the rest, read on.
                $resumeAt = $tagStart === false ? strlen($this->buffer) : $tagStart;
                $keepFrom = $resumeAt;

                if ($depth === 1) {
                    $keepFrom = min($keepFrom, $textStart);
                }

                if ($datasetTagStart !== null) {
                    $keepFrom = min($keepFrom, $datasetTagStart);
                }

                if (! $stripping) {
                    $this->write(substr($this->buffer, $copyFrom, $keepFrom - $copyFrom));
                }

                $this->buffer = substr($this->buffer, $keepFrom);
                $position = $resumeAt - $keepFrom;
                $copyFrom = 0;
                $textStart -= $keepFrom;

                if ($datasetTagStart !== null) {
                    $datasetTagStart -= $keepFrom;
                    $datasetInnerStart -= $keepFrom;
                }

                $this->readChunk();

                continue;
            }

            $position = $tagEnd + 1;
            $marker = $this->buffer[$tagStart + 1];

            if ($marker === '!' || $marker === '?') {
                // Comment, CDATA, PI, DOCTYPE or the declaration: copied as is.
                if ($depth === 1 && ! $stripping) {
                    $textStart = $position;
                }

                continue;
            }

            if ($marker === '/') {
                $depth--;

                if ($stripping) {
                    if ($depth === $stripDepth) {
                        $stripping = false;
                        $copyFrom = $position;
                        $textStart = $position;

                        if ($strippedName === 'Broadcast') {
                            $strippedBroadcasts++;
                        } else {
                            $strippedParameters++;
                        }
                    }

                    continue;
                }

                if ($datasetTagStart !== null && $depth === 2) {
                    $innerLength = $tagStart - $datasetInnerStart;
                    $original = $this->decodeText(substr($this->buffer, $datasetInnerStart, $innerLength));
                    $datasetIds[] = $original;

                    if ($escapedOverride !== null && $original !== $datasetIdOverride) {
                        $this->write(substr($this->buffer, $copyFrom, $datasetInnerStart - $copyFrom).$escapedOverride);
                        $copyFrom = $tagStart;
                        $datasetIdChanged = true;
                    }

                    $datasetTagStart = null;
                } elseif ($depth === 1) {
                    $inInputReference = false;
                    $textStart = $position;
                } elseif ($depth === 0 && $rootSeen && ! $rootClosed) {
                    $rootClosed = true;

                    if ($injection !== null) {
                        $this->write(substr($this->buffer, $copyFrom, $textStart - $copyFrom)
                            .$this->injectionXml($injection, $rootPrefix, $childSeparator ?? ''));
                        $copyFrom = $textStart;
                    }
                }

                continue;
            }

            // A start tag (or an empty-element tag).
            $selfClosing = $this->buffer[$tagEnd - 1] === '/';

            if ($depth <= 2 && ! $stripping) {
                $name = substr($this->buffer, $tagStart + 1, strcspn($this->buffer, " \t\r\n/>", $tagStart + 1));
                $colon = strpos($name, ':');
                $localName = $colon === false ? $name : substr($name, $colon + 1);

                if ($depth === 0 && ! $rootSeen) {
                    if (! in_array($localName, self::ROOT_NAMES, true)) {
                        if ($injection !== null) {
                            throw new RuntimeException(self::NO_ROOT_MESSAGE);
                        }

                        // Not schedule data and nothing to inject: the copy is the file unchanged.
                        return $this->copyRest($copyFrom);
                    }

                    $rootSeen = true;
                    $rootPrefix = $colon === false ? '' : substr($name, 0, $colon);

                    if ($selfClosing) {
                        $rootClosed = true;

                        if ($injection !== null) {
                            $openTag = rtrim(substr($this->buffer, $tagStart, $tagEnd - 1 - $tagStart)).'>';
                            $this->write(substr($this->buffer, $copyFrom, $tagStart - $copyFrom)
                                .$openTag.$this->injectionXml($injection, $rootPrefix, '')."</{$name}>");
                            $copyFrom = $position;
                        }

                        continue;
                    }

                    $depth = 1;
                    $textStart = $position;

                    continue;
                }

                if ($depth === 1) {
                    $textRun = substr($this->buffer, $textStart, $tagStart - $textStart);
                    $whitespaceOnly = strspn($textRun, " \t\r\n") === strlen($textRun);

                    if ($injection !== null && in_array($localName, self::STRIPPED_ENTITIES, true)) {
                        $keepUntil = $whitespaceOnly ? $textStart : $tagStart;
                        $this->write(substr($this->buffer, $copyFrom, $keepUntil - $copyFrom));
                        $copyFrom = $position;

                        if ($selfClosing) {
                            $textStart = $position;
                            $localName === 'Broadcast' ? $strippedBroadcasts++ : $strippedParameters++;
                        } else {
                            $stripping = true;
                            $stripDepth = 1;
                            $strippedName = $localName;
                            $depth = 2;
                        }

                        continue;
                    }

                    if ($whitespaceOnly && $childSeparator === null) {
                        $childSeparator = $textRun;
                    }

                    if ($selfClosing) {
                        $textStart = $position;
                    } else {
                        $inInputReference = $localName === 'Input_Reference';
                        $depth = 2;
                    }

                    continue;
                }

                if ($depth === 2 && $inInputReference && $localName === 'dataset_id') {
                    if ($selfClosing) {
                        $datasetIds[] = '';

                        if ($escapedOverride !== null && $datasetIdOverride !== '') {
                            $openTag = rtrim(substr($this->buffer, $tagStart, $tagEnd - 1 - $tagStart)).'>';
                            $this->write(substr($this->buffer, $copyFrom, $tagStart - $copyFrom)
                                .$openTag.$escapedOverride."</{$name}>");
                            $copyFrom = $position;
                            $datasetIdChanged = true;
                        }

                        continue;
                    }

                    $datasetTagStart = $tagStart;
                    $datasetInnerStart = $position;
                }
            }

            if (! $selfClosing) {
                $depth++;
            }
        }

        if (! $rootSeen && $injection !== null) {
            throw new RuntimeException(self::NO_ROOT_MESSAGE);
        }

        if ($rootSeen && ! $rootClosed) {
            throw new RuntimeException('The schedule data file ended inside the root element - it looks truncated.');
        }

        $this->write(substr($this->buffer, $copyFrom));

        return $this->report(array_values(array_unique($datasetIds)), $datasetIdChanged, $strippedBroadcasts, $strippedParameters);
    }

    /**
     * The offset of the last byte of the markup construct starting at the
     * given '<', or false when it does not end inside the buffer.
     */
    private function findConstructEnd(int $tagStart): int|false
    {
        $buffer = $this->buffer;

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
            return $this->findQuotedEnd($tagStart + 2, true);
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

        return $this->findQuotedEnd($tagStart + 1, false);
    }

    private function findQuotedEnd(int $from, bool $allowSubset): int|false
    {
        $buffer = $this->buffer;
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

    private function injectionXml(BroadcastInjection $injection, string $prefix, string $separator): string
    {
        $qualified = static fn (string $name): string => $prefix !== '' ? "{$prefix}:{$name}" : $name;
        $element = fn (string $name, string $value): string => '<'.$qualified($name).'>'.$this->escapeText($value).'</'.$qualified($name).'>';

        $broadcast = '';

        foreach ($injection->broadcastFields(typed: false) as $field => $value) {
            $broadcast .= $element($field, (string) $value);
        }

        $xml = $separator.'<'.$qualified('Broadcast').'>'.$broadcast.'</'.$qualified('Broadcast').'>';

        foreach ($injection->parameterRows('application/xml') as $row) {
            $parameter = '';

            foreach ($row as $field => $value) {
                $parameter .= $element($field, $value);
            }

            $xml .= $separator.'<'.$qualified('Broadcast_Parameter').'>'.$parameter.'</'.$qualified('Broadcast_Parameter').'>';
        }

        return $xml;
    }

    private function escapeText(string $value, bool $rejectInvalid = false): string
    {
        if ($rejectInvalid && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', $value))) {
            throw new RuntimeException('The dataset ID contains characters XML cannot hold.');
        }

        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // Non-ASCII as character references: valid in whatever encoding the file declares.
        return mb_encode_numericentity($escaped, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8', true);
    }

    private function decodeText(string $raw): string
    {
        if (strpbrk($raw, '&<') === false) {
            return $raw;
        }

        $document = new DOMDocument;

        if (@$document->loadXML('<value>'.$raw.'</value>', LIBXML_NONET)) {
            return $document->documentElement->textContent;
        }

        return html_entity_decode($raw, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function looksLikeUtf16(): bool
    {
        $start = substr($this->buffer, 0, 2);

        return $start === "\xFF\xFE" || $start === "\xFE\xFF" || $start === "<\x00" || $start === "\x00<";
    }

    private function readChunk(): void
    {
        $chunk = fread($this->source, self::READ_CHUNK_BYTES);

        if ($chunk === false) {
            throw new RuntimeException('The schedule data file could not be read.');
        }

        $this->buffer .= $chunk;
        $this->endOfFile = feof($this->source) || $chunk === '';
    }

    private function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $written = fwrite($this->destination, $bytes);

        if ($written === false || $written < strlen($bytes)) {
            throw new RuntimeException('The rewritten schedule data could not be written.');
        }

        $this->bytesWritten += $written;
    }

    /**
     * Writes the buffer from the given offset and the rest of the source unchanged.
     */
    private function copyRest(int $from): RewriteReport
    {
        $this->write(substr($this->buffer, $from));
        $this->buffer = '';

        while (! $this->endOfFile) {
            $this->readChunk();
            $this->write($this->buffer);
            $this->buffer = '';
        }

        return $this->report([], false, 0, 0);
    }

    /**
     * @param  list<string>  $datasetIds
     */
    private function report(array $datasetIds, bool $datasetIdChanged, int $strippedBroadcasts, int $strippedParameters): RewriteReport
    {
        return new RewriteReport(
            format: 'xml',
            datasetIds: $datasetIds,
            datasetIdChanged: $datasetIdChanged,
            strippedBroadcasts: $strippedBroadcasts,
            strippedBroadcastParameters: $strippedParameters,
            bytesWritten: $this->bytesWritten,
        );
    }
}
