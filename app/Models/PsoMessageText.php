<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One PSO message text in one language. A single global table, refilled from an Unfiled message texts upload. */
class PsoMessageText extends Model
{
    protected $fillable = ['language_id', 'message_id', 'text'];

    /** @return array<string, string> message id (as a string) => text */
    public static function asMap(string $languageId = 'en'): array
    {
        $map = [];
        foreach (static::query()->where('language_id', $languageId)->orderBy('message_id')->toBase()->get(['message_id', 'text']) as $row) {
            $map[(string) $row->message_id] = (string) $row->text;
        }

        return $map;
    }
}
