<?php

namespace App\Filament\Navigation;

/** Sidebar groups, named by what the consultant is trying to do. Order here is the sidebar order. */
final class Groups
{
    public const ANALYSE = 'Analysis tools';

    public const LIVE = 'Live PSO actions';

    public const SETUP = 'Setup';

    public const ADMIN = 'Admin';

    /** @return list<string> */
    public static function ordered(): array
    {
        return [self::ANALYSE, self::LIVE, self::SETUP, self::ADMIN];
    }
}
