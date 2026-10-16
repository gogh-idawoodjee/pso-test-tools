<?php

namespace App\Support\ScheduleData;

use RuntimeException;

/**
 * Copies a schedule data JSON file token by token. Everything outside the
 * Input_Reference dataset_id values and the Broadcast/Broadcast_Parameter
 * members of the top-level wrapper is written exactly as read.
 *
 * 6.15+ files wrap in "ScheduleData" with native types and always-array
 * entities; older files wrap in "dsScheduleData", use string values and only
 * array-wrap an entity with more than one row. The injected rows match.
 */
class JsonScheduleDataRewriter implements ScheduleDataRewriter
{
    private const array WRAPPER_KEYS = ['ScheduleData', 'dsScheduleData'];

    private const array STRIPPED_ENTITIES = ['Broadcast', 'Broadcast_Parameter'];

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private const int WRITE_BUFFER_BYTES = 262144;

    public function __construct(private JsonTokenReader $tokenReader) {}

    public function rewrite(
        string $sourcePath,
        string $destinationPath,
        ?string $datasetIdOverride,
        ?BroadcastInjection $injection,
    ): RewriteReport {
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

        try {
            return $this->copy($source, $destination, $datasetIdOverride, $injection);
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    /**
     * @param  resource  $source
     * @param  resource  $destination
     */
    private function copy($source, $destination, ?string $datasetIdOverride, ?BroadcastInjection $injection): RewriteReport
    {
        $bytesWritten = 0;
        $pending = '';

        $flush = static function () use ($destination, &$pending, &$bytesWritten): void {
            if ($pending === '') {
                return;
            }

            $written = fwrite($destination, $pending);

            if ($written === false || $written < strlen($pending)) {
                throw new RuntimeException('The rewritten schedule data could not be written.');
            }

            $bytesWritten += $written;
            $pending = '';
        };

        $write = static function (string $text) use (&$pending, $flush): void {
            $pending .= $text;

            if (strlen($pending) >= self::WRITE_BUFFER_BYTES) {
                $flush();
            }
        };

        $depth = 0;
        $previousSignificant = '';
        $pendingWrapperKey = null;
        $format = null;

        // Inside the wrapper object; null while outside it.
        $memberState = null; // 'key' | 'colon' | 'valueStart' | 'value'
        $leadingWhitespace = null; // whitespace between the wrapper's '{' and its first key
        $whitespaceBeforeComma = '';
        $whitespaceAfterComma = '';
        $sawComma = false;
        $wroteMember = false;
        $strippingMember = false;
        $memberName = '';
        $memberDepth = 0;
        $memberRows = 0;
        $memberIsArray = false;
        $inInputReference = false;
        $datasetStage = 0; // 0 idle, 1 saw the dataset_id key, 2 saw its colon
        $datasetIds = [];
        $datasetIdChanged = false;
        $strippedBroadcasts = 0;
        $strippedParameters = 0;

        foreach ($this->tokenReader->tokens($source) as $token) {
            $isWhitespace = $token[0] === ' ' || $token[0] === "\t" || $token[0] === "\r" || $token[0] === "\n";

            if ($memberState === null) {
                $write($token);

                if ($isWhitespace) {
                    continue;
                }

                $before = $previousSignificant;
                $previousSignificant = $token;

                if ($pendingWrapperKey !== null && $token !== ':' && $token !== '{') {
                    $pendingWrapperKey = null;
                }

                if ($token === '{' && $pendingWrapperKey !== null && $depth === 1) {
                    $format = $pendingWrapperKey;
                    $pendingWrapperKey = null;
                    $depth = 2;
                    $memberState = 'key';

                    continue;
                }

                if ($token === '{' || $token === '[') {
                    $depth++;
                } elseif ($token === '}' || $token === ']') {
                    $depth--;
                } elseif ($token[0] === '"' && $depth === 1 && $format === null && ($before === '{' || $before === ',')) {
                    $key = json_decode($token);
                    $pendingWrapperKey = is_string($key) && in_array($key, self::WRAPPER_KEYS, true) ? $key : null;
                }

                continue;
            }

            if ($isWhitespace) {
                if ($memberState === 'key') {
                    if ($sawComma) {
                        $whitespaceAfterComma .= $token;
                    } else {
                        $whitespaceBeforeComma .= $token;
                    }
                } elseif (! $strippingMember) {
                    $write($token);
                }

                continue;
            }

            $before = $previousSignificant;
            $previousSignificant = $token;

            if ($memberState === 'key') {
                if ($token === ',') {
                    $sawComma = true;

                    continue;
                }

                if ($token === '}') {
                    if ($injection !== null) {
                        foreach ($this->injectedEntities($injection, $format) as $entityName => $entityJson) {
                            $write(($wroteMember ? ',' : '').json_encode($entityName).':'.$entityJson);
                            $wroteMember = true;
                        }
                    }

                    $write($whitespaceBeforeComma.$whitespaceAfterComma.'}');
                    $whitespaceBeforeComma = '';
                    $whitespaceAfterComma = '';
                    $sawComma = false;
                    $memberState = null;
                    $depth = 1;

                    continue;
                }

                $leadingWhitespace ??= $whitespaceBeforeComma.$whitespaceAfterComma;

                $decodedKey = json_decode($token);
                $memberName = is_string($decodedKey) ? $decodedKey : '';
                // Old broadcasts are only stripped when a new one replaces them (as in the script).
                $strippingMember = $injection !== null && in_array($memberName, self::STRIPPED_ENTITIES, true);
                $memberRows = 0;
                $memberIsArray = false;

                if (! $strippingMember) {
                    // The separator keeps its own whitespace; the first kept member
                    // takes the whitespace that followed the wrapper's '{'.
                    $write(($wroteMember ? $whitespaceBeforeComma.','.$whitespaceAfterComma : $leadingWhitespace).$token);
                    $wroteMember = true;
                }

                $whitespaceBeforeComma = '';
                $whitespaceAfterComma = '';
                $sawComma = false;
                $memberState = 'colon';

                continue;
            }

            if ($memberState === 'colon') {
                if (! $strippingMember) {
                    $write($token);
                }

                if ($token === ':') {
                    $memberState = 'valueStart';
                }

                continue;
            }

            if ($memberState === 'valueStart') {
                if ($token === '{' || $token === '[') {
                    $memberDepth = 1;
                    $memberIsArray = $token === '[';
                    $memberState = 'value';
                    $inInputReference = $memberName === 'Input_Reference';
                    $datasetStage = 0;

                    if ($strippingMember && ! $memberIsArray) {
                        $memberRows = 1;
                    }

                    if (! $strippingMember) {
                        $write($token);
                    }

                    continue;
                }

                // A scalar member value (null, a number, a string).
                if (! $strippingMember) {
                    $write($token);
                }

                $memberState = 'key';
                $strippingMember = false;

                continue;
            }

            // $memberState === 'value'
            $output = $token;

            if ($token === '{' || $token === '[') {
                if ($strippingMember && $memberIsArray && $token === '{' && $memberDepth === 1) {
                    $memberRows++;
                }

                $memberDepth++;
            } elseif ($token === '}' || $token === ']') {
                $memberDepth--;
            }

            if ($inInputReference && ! $strippingMember) {
                if ($datasetStage === 0) {
                    // A row's own key: depth 2 inside an array of rows, depth 1 for a single legacy row.
                    if ($token[0] === '"'
                        && $memberDepth === ($memberIsArray ? 2 : 1)
                        && ($before === '{' || $before === ',')
                        && json_decode($token) === 'dataset_id') {
                        $datasetStage = 1;
                    }
                } elseif ($datasetStage === 1) {
                    $datasetStage = $token === ':' ? 2 : 0;
                } else {
                    $datasetStage = 0;

                    if ($token !== '{' && $token !== '[') {
                        $original = json_decode($token);
                        $originalText = is_scalar($original) ? (string) $original : '';
                        $datasetIds[] = $originalText;

                        if ($datasetIdOverride !== null) {
                            $output = json_encode($datasetIdOverride, self::JSON_FLAGS);

                            if ($originalText !== $datasetIdOverride) {
                                $datasetIdChanged = true;
                            }
                        }
                    }
                }
            }

            if (! $strippingMember) {
                $write($output);
            }

            if ($memberDepth === 0) {
                if ($strippingMember) {
                    if ($memberName === 'Broadcast') {
                        $strippedBroadcasts += $memberRows;
                    } else {
                        $strippedParameters += $memberRows;
                    }
                }

                $memberState = 'key';
                $strippingMember = false;
                $inInputReference = false;
            }
        }

        if ($memberState !== null) {
            throw new RuntimeException("The schedule data file ended inside the '{$format}' wrapper - it looks truncated.");
        }

        if ($format === null && $injection !== null) {
            throw new MissingScheduleDataWrapperException;
        }

        $flush();

        // No wrapper and nothing to inject: the copy is the file unchanged (as in the script).
        return new RewriteReport(
            format: $format,
            wrapperFound: $format !== null,
            datasetIds: array_values(array_unique($datasetIds)),
            datasetIdChanged: $datasetIdChanged,
            strippedBroadcasts: $strippedBroadcasts,
            strippedBroadcastParameters: $strippedParameters,
            bytesWritten: $bytesWritten,
        );
    }

    /**
     * @return array<string, string> entity name => JSON text of its value
     */
    private function injectedEntities(BroadcastInjection $injection, string $format): array
    {
        $typed = $format === 'ScheduleData';
        $broadcast = $injection->broadcastFields($typed);

        return [
            'Broadcast' => json_encode($typed ? [$broadcast] : $broadcast, self::JSON_FLAGS),
            'Broadcast_Parameter' => json_encode($injection->parameterRows('application/json'), self::JSON_FLAGS),
        ];
    }
}
