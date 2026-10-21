<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One PSO message text in one language. A single global table, refilled from the Settings page. */
class PsoMessageText extends Model
{
    protected $fillable = ['language_id', 'message_id', 'text'];

    /**
     * Texts for one language. With no language given, the best loaded one: exactly "en", then an english
     * variant such as "EN-GB", then whichever language has the most texts.
     *
     * @return array<string, string> message id (as a string) => text
     */
    public static function asMap(?string $languageId = null): array
    {
        $languageId ??= static::preferredLanguage();
        if ($languageId === null) {
            return [];
        }

        $map = [];
        foreach (static::query()->where('language_id', $languageId)->orderBy('message_id')->toBase()->get(['message_id', 'text']) as $row) {
            $map[(string) $row->message_id] = (string) $row->text;
        }

        return $map;
    }

    public static function preferredLanguage(): ?string
    {
        $counts = static::query()->toBase()
            ->selectRaw('language_id, count(*) as text_count')
            ->groupBy('language_id')
            ->pluck('text_count', 'language_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
        if ($counts === []) {
            return null;
        }

        $languages = array_map('strval', array_keys($counts));
        if (in_array('en', $languages, true)) {
            return 'en';
        }

        $english = array_values(array_filter(
            $languages,
            static fn (string $language): bool => preg_match('/^en(?:[-_].*)?$/i', $language) === 1,
        ));
        if ($english !== []) {
            usort($english, static fn (string $left, string $right): int => $counts[$right] <=> $counts[$left] ?: strcmp($left, $right));

            return $english[0];
        }

        uksort($counts, static fn (string|int $left, string|int $right): int => $counts[$right] <=> $counts[$left] ?: strcmp((string) $left, (string) $right));

        return (string) array_key_first($counts);
    }
}
