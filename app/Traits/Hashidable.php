<?php

namespace App\Traits;

use Vinkla\Hashids\Facades\Hashids;

trait Hashidable
{
    public function getHashid(): string
    {
        return Hashids::encode($this->getKey());
    }

    public static function findByHashid(string $hashid)
    {
        $decoded = Hashids::decode($hashid);
        return isset($decoded[0]) ? static::find($decoded[0]) : null;
    }

    public static function findOrFailByHashid(string $hashid)
    {
        $decoded = Hashids::decode($hashid);
        if (!isset($decoded[0])) {
            abort(404);
        }
        return static::findOrFail($decoded[0]);
    }
}
