<?php

namespace App\Support\Settings;

use App\Support\SysCompare\ParameterCatalog;
use App\Tools\LoadAudit\PsoToolsCheckout;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The two reference files the tools read, each either the copy shipped in pso-tools/kb or one built on the Settings
 * page from IFS's own documents: the release-notes watchlist (from the Public Release Notes PDF) and the parameter
 * catalog (from the saved docs Parameters page). The builders are the pso-tools scripts, so the web and the CLI agree.
 */
class KbFiles
{
    /**
     * Runs a builder on an upload and returns its RESULT fields (key => value).
     *
     * @return array<string, string>
     *
     * @throws InvalidKbFile in plain words when the builder refuses the file
     */
    public static function build(PsoToolsCheckout $checkout, string $script, string $input, string $output): array
    {
        $done = Process::timeout(180)->env(['LANG' => 'C.UTF-8'])->run([$checkout->python(), '-I', $script, $input, $output]);
        if (! $done->successful()) {
            $reason = trim($done->errorOutput()) ?: trim($done->output());
            $last = trim((string) last(preg_split('/\r?\n/', $reason)));
            if (str_contains($last, 'pdftotext is not installed')) {
                throw new InvalidKbFile('This server cannot read PDFs yet: poppler-utils needs installing on it (apt install poppler-utils).');
            }

            throw new InvalidKbFile($last !== '' ? $last : 'The file could not be read.');
        }

        if (preg_match('/^RESULT (.+)$/m', $done->output(), $match) !== 1) {
            throw new InvalidKbFile('The file could not be read.');
        }

        $fields = [];
        foreach (preg_split('/\s+/', trim($match[1])) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[$key] = $value;
        }

        return $fields;
    }

    /** The watchlist the Load audit reads: the uploaded one copied into the run's folder, else the shipped one. */
    public static function watchlistPath(PsoToolsCheckout $checkout, string $workDir): string
    {
        return self::stored(SettingsFileStore::RELEASE_NOTES, $workDir) ?? $checkout->watchlistFile();
    }

    /** The parameter catalog the Load audit reads: the uploaded one copied into the run's folder, else the shipped one. */
    public static function paramsPath(PsoToolsCheckout $checkout, string $workDir): string
    {
        return self::stored(SettingsFileStore::PARAM_REFERENCE, $workDir) ?? $checkout->paramsFile();
    }

    /** The catalog Sys compare applies defaults from: the uploaded one when there is one, else the built-in one. */
    public static function catalog(string $scratchDirectory): ParameterCatalog
    {
        $record = app(AppSettings::class)->paramReference();
        $path = $record === null ? null : self::stored(SettingsFileStore::PARAM_REFERENCE, $scratchDirectory);
        if ($path === null) {
            return ParameterCatalog::builtIn();
        }

        $version = trim((string) ($record['pso_version'] ?? ''));

        return ParameterCatalog::fromCsv($path, $version !== '' ? $version : null);
    }

    /** A stored file copied into the folder, or null when there is none (or the store cannot be reached). */
    private static function stored(string $key, string $directory): ?string
    {
        $store = app(SettingsFileStore::class);
        try {
            if (! $store->exists($key)) {
                return null;
            }
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }

            return $store->readToScratch($key, $directory);
        } catch (Throwable) {
            return null;
        }
    }
}
