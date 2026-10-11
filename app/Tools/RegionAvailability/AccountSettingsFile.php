<?php

namespace App\Tools\RegionAvailability;

use App\Support\DatasetSettings;
use stdClass;

/**
 * The dataset's settings written as the account file region_availability.py reads
 * (accounts/<ACCOUNT>.json under --accounts-dir). The checkout's own accounts folder is never used on
 * the web: what the dataset's edit form says is the only source.
 */
class AccountSettingsFile
{
    public const string DIR = 'accounts';

    /** The name the file is written under and passed as --account: letters and digits only, upper case; ACCOUNT when nothing is left. */
    public static function accountName(?string $accountId): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $accountId));

        return $clean === '' ? 'ACCOUNT' : $clean;
    }

    /** Writes <accountsDir>/<account>.json (creating the folder) and returns its path. No settings writes {}. */
    public static function write(?DatasetSettings $settings, string $accountsDir, string $account): string
    {
        $accountsDir = rtrim($accountsDir, '/');
        if (! is_dir($accountsDir)) {
            mkdir($accountsDir, 0770, true);
        }

        $data = $settings?->toAccountFile() ?? [];
        $path = "{$accountsDir}/{$account}.json";
        file_put_contents($path, json_encode($data === [] ? new stdClass : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
    }
}
