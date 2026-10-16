<?php

namespace App\Support\GatewayUpload;

/**
 * Caps text bound for a database column so MySQL strict mode never refuses
 * the row. The cut lands on a UTF-8 character boundary and the result,
 * marker included, never exceeds the limit.
 */
class StoredText
{
    public const int LOG_LINE_BYTES = 60000;

    public const int ERROR_MESSAGE_BYTES = 60000;

    public const int RESPONSE_BODY_BYTES = 1048576;

    public const int DATASET_ID_BYTES = 255;

    public const string TRUNCATED_MARKER = "\n(truncated)";

    public static function cap(string $text, int $limitBytes): string
    {
        if (strlen($text) <= $limitBytes) {
            return $text;
        }

        return mb_strcut($text, 0, $limitBytes - strlen(self::TRUNCATED_MARKER), 'UTF-8').self::TRUNCATED_MARKER;
    }
}
