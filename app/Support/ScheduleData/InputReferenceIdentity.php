<?php

namespace App\Support\ScheduleData;

use DateTimeInterface;
use DateTimeZone;

/**
 * The `user_id` and `description` written into every root-level
 * Input_Reference of the copy sent to PSO, so the load says where it came
 * from. Both are always written: an existing value is replaced, a missing
 * one is added. Never carries a password, token or key.
 */
readonly class InputReferenceIdentity
{
    public const string USER_ID = 'PSO Test Tools';

    public string $userId;

    public string $description;

    public function __construct(string $userId, string $description)
    {
        $this->userId = self::clean($userId);
        $this->description = self::clean($description);
    }

    /**
     * One plain line, e.g. "Sent from PSO Test Tools (Load from File) by Ish
     * Dawoodjee, environment SaskTel TST, file load.xml, 2026-10-16 13:40Z".
     */
    public static function forLoadFromFile(string $userName, string $environmentName, string $fileName, DateTimeInterface $queuedAt): self
    {
        $utc = (clone $queuedAt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');

        return new self(
            self::USER_ID,
            'Sent from '.self::USER_ID." (Load from File) by {$userName}, environment {$environmentName}, file {$fileName}, {$utc}Z",
        );
    }

    /**
     * Valid UTF-8, one line, no control characters: safe in JSON and in XML
     * whatever the file declares.
     */
    private static function clean(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = (string) preg_replace('/[\x00-\x1F\x7F\x{FFFE}\x{FFFF}]+/u', ' ', $value);

        return trim((string) preg_replace('/ {2,}/', ' ', $value));
    }
}
