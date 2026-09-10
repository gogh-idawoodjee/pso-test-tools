<?php

namespace App\Support;

class GatewayUploadPath
{
    /**
     * The only shape of r2 key the gateway upload field can legitimately
     * produce: its `->directory('gateway-uploads')`, plus the hashed
     * (ULID) filename Filament generates by default, plus one of the
     * three extensions this feature accepts.
     *
     * `\z` rather than `$` on purpose — `$` also matches just before a
     * trailing newline, which would let `gateway-uploads/x.json\n` through.
     */
    public const string PATTERN = '/^gateway-uploads\/[A-Za-z0-9]+\.(json|xml|zip)\z/i';

    /**
     * Whether $path is a key this application itself wrote under
     * `gateway-uploads/`, and is therefore safe to read from and delete
     * on the shared r2 bucket.
     *
     * The upload field's state is part of the public `$data` Livewire
     * property, so its value is whatever the browser sends: any r2 key
     * derived from it has to be constrained before it is used.
     */
    public static function isAllowed(?string $path): bool
    {
        return filled($path) && preg_match(self::PATTERN, $path) === 1;
    }
}
