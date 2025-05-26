<?php

namespace App\Traits;

use Override;

trait AdminViewable
{

    #[Override] public static function canAccess(): bool
    {
        return auth()->user()->email === 'idawoodjee@mac.com';
    }

}
