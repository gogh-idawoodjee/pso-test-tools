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

    /** PSO's limit for Input_Reference.description is not documented; 255 is the safe guess. */
    public const int DESCRIPTION_MAX_CHARACTERS = 255;

    private const string ELLIPSIS = '...';

    public string $userId;

    public string $description;

    public function __construct(string $userId, string $description)
    {
        $this->userId = self::clean($userId);
        $this->description = self::clean($description);
    }

    /**
     * One plain line, e.g. "Sent from PSO Test Tools (Load from File),
     * environment SaskTel TST, file load.xml, 2026-10-16 13:40Z".
     *
     * Capped at DESCRIPTION_MAX_CHARACTERS: the file name is shortened first
     * (keeping its extension), then the environment name, so the fixed text,
     * and the time survive.
     */
    public static function forLoadFromFile(string $environmentName, string $fileName, DateTimeInterface $queuedAt): self
    {
        $utc = (clone $queuedAt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
        $environmentName = self::clean($environmentName);
        $fileName = self::clean($fileName);

        $compose = static fn (string $environment, string $file): string => 'Sent from '.self::USER_ID." (Load from File), environment {$environment}, file {$file}, {$utc}Z";

        $budget = self::DESCRIPTION_MAX_CHARACTERS - mb_strlen($compose('', ''));

        if (mb_strlen($environmentName) + mb_strlen($fileName) > $budget) {
            $fileName = self::shorten($fileName, max($budget - mb_strlen($environmentName), 0), keepExtension: true);
            $environmentName = self::shorten($environmentName, max($budget - mb_strlen($fileName), 0), keepExtension: false);
        }

        // Only an absurdly long environment name gets here; cut the line itself.
        return new self(self::USER_ID, mb_substr($compose($environmentName, $fileName), 0, self::DESCRIPTION_MAX_CHARACTERS));
    }

    /**
     * Shortens from the middle ("north_tr...1005.xml"), keeping the extension
     * when asked. Never shorter than a few characters either side of the
     * ellipsis, so the result can exceed a very small limit.
     */
    private static function shorten(string $text, int $maxCharacters, bool $keepExtension): string
    {
        if (mb_strlen($text) <= $maxCharacters) {
            return $text;
        }

        $extension = $keepExtension ? (string) pathinfo($text, PATHINFO_EXTENSION) : '';
        $extension = $extension !== '' && mb_strlen($extension) <= 10 ? '.'.$extension : '';
        $stem = $extension === '' ? $text : mb_substr($text, 0, mb_strlen($text) - mb_strlen($extension));

        $keep = max($maxCharacters - mb_strlen(self::ELLIPSIS) - mb_strlen($extension), 4);
        $head = (int) ceil($keep / 2);
        $tail = $keep - $head;

        return mb_substr($stem, 0, $head).self::ELLIPSIS.mb_substr($stem, -$tail).$extension;
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
