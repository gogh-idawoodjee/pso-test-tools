<?php

namespace App\Support\SysCompare;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Works out each environment's PSO version from System_Version.
 *
 *  - Current version = highest version_id, compared numerically by dotted parts
 *    (6.13.0.9 is older than 6.13.0.67).
 *  - Last upgrade    = most recent "Upgrade from ..." record, by version_stamp.
 *  - Last patch      = most recent "Update ..." record, by version_stamp.
 *  - Created         = the "Creation" record.
 */
class VersionAnalyzer
{
    /**
     * @param  list<SysEnvironment>  $environments
     */
    public function analyze(array $environments, DateTimeImmutable $now): VersionReport
    {
        $analyzed = array_map(
            fn (SysEnvironment $environment): EnvironmentVersion => $this->analyzeEnvironment($environment, $now),
            $environments,
        );

        $known = array_values(array_filter($analyzed, static fn (EnvironmentVersion $version): bool => $version->hasData));
        $missingNames = array_values(array_map(
            static fn (EnvironmentVersion $version): string => $version->name,
            array_filter($analyzed, static fn (EnvironmentVersion $version): bool => ! $version->hasData),
        ));

        $highest = null;

        foreach ($known as $version) {
            if ($highest === null || self::compareVersions($version->current, $highest) > 0) {
                $highest = $version->current;
            }
        }

        $distinctVersions = array_unique(array_map(static fn (EnvironmentVersion $version): string => $version->current, $known));
        $distinctReleases = array_unique(array_map(static fn (EnvironmentVersion $version): string => $version->release, $known));

        $comparable = count($known) >= 2;
        $mismatch = $comparable && count($distinctVersions) > 1;

        $analyzed = array_map(
            static function (EnvironmentVersion $version) use ($highest): EnvironmentVersion {
                if (! $version->hasData) {
                    return $version;
                }

                return $version->withStatus(
                    self::compareVersions($version->current, (string) $highest) === 0
                        ? EnvironmentVersion::LATEST
                        : EnvironmentVersion::BEHIND,
                );
            },
            $analyzed,
        );

        return new VersionReport(
            environments: $analyzed,
            highestVersion: (string) $highest,
            comparable: $comparable,
            mismatch: $mismatch,
            kindText: $mismatch ? $this->describeMismatch($known, $distinctReleases) : '',
            knownCount: count($known),
            missingNames: $missingNames,
        );
    }

    /**
     * Numeric comparison of dotted versions. A value that is not a 2-4 part dotted
     * number counts as 0.0, and a missing part counts as lower than a present one
     * (6.13 is older than 6.13.0), matching .NET's System.Version.
     */
    public static function compareVersions(string $left, string $right): int
    {
        return self::versionParts($left) <=> self::versionParts($right);
    }

    /**
     * @return list<int>
     */
    public static function versionParts(string $version): array
    {
        $version = trim($version);

        if (preg_match('/^\d+(\.\d+){1,3}$/', $version) !== 1) {
            return [0, 0, -1, -1];
        }

        $parts = array_map('intval', explode('.', $version));

        return array_pad($parts, 4, -1);
    }

    public static function releaseOf(string $version): string
    {
        $parts = self::versionParts($version);

        return $parts[0].'.'.$parts[1];
    }

    private function analyzeEnvironment(SysEnvironment $environment, DateTimeImmutable $now): EnvironmentVersion
    {
        $records = array_map(
            fn (array $row): VersionRecord => new VersionRecord(
                version: $row['version_id'] ?? '',
                type: $row['version_type'] ?? '',
                user: $row['version_user'] ?? '',
                stamp: $this->parseStamp($row['version_stamp'] ?? ''),
            ),
            $environment->sysFile->rows('System_Version'),
        );

        if ($records === []) {
            return new EnvironmentVersion(name: $environment->name, hasData: false);
        }

        $current = $records[0];

        foreach ($records as $record) {
            if (self::compareVersions($record->version, $current->version) >= 0) {
                $current = $record;
            }
        }

        $upgrade = $this->latestByStamp($records, static fn (VersionRecord $record): bool => self::startsWith($record->type, 'Upgrade from'));
        $patch = $this->latestByStamp($records, static fn (VersionRecord $record): bool => self::startsWith($record->type, 'Update'));
        $creation = null;

        foreach ($records as $record) {
            if ($record->stamp !== null && strcasecmp($record->type, 'Creation') === 0) {
                $creation = $record;

                break;
            }
        }

        $history = $records;
        usort($history, static fn (VersionRecord $left, VersionRecord $right): int => ($left->stamp?->getTimestamp() ?? PHP_INT_MIN) <=> ($right->stamp?->getTimestamp() ?? PHP_INT_MIN));

        return new EnvironmentVersion(
            name: $environment->name,
            hasData: true,
            current: $current->version,
            release: self::releaseOf($current->version),
            upgradedFrom: $upgrade !== null ? trim((string) preg_replace('/^Upgrade from\s*/i', '', $upgrade->type)) : '',
            upgradedTo: $upgrade?->version ?? '',
            upgradedAt: $upgrade?->stamp,
            daysSinceUpgrade: $upgrade?->stamp !== null ? (int) floor(($now->getTimestamp() - $upgrade->stamp->getTimestamp()) / 86400) : null,
            patchVersion: $patch?->version ?? '',
            patchedAt: $patch?->stamp,
            createdAt: $creation?->stamp,
            history: $history,
        );
    }

    /**
     * @param  list<VersionRecord>  $records
     * @param  callable(VersionRecord): bool  $matches
     */
    private function latestByStamp(array $records, callable $matches): ?VersionRecord
    {
        $candidates = array_values(array_filter(
            $records,
            static fn (VersionRecord $record): bool => $record->stamp !== null && $matches($record),
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (VersionRecord $left, VersionRecord $right): int => $left->stamp->getTimestamp() <=> $right->stamp->getTimestamp());

        return $candidates[array_key_last($candidates)];
    }

    /**
     * @param  list<EnvironmentVersion>  $known
     * @param  array<int, string>  $distinctReleases
     */
    private function describeMismatch(array $known, array $distinctReleases): string
    {
        if (count($distinctReleases) > 1) {
            return 'Different RELEASES: '.implode(', ', array_map(
                static fn (EnvironmentVersion $version): string => $version->name.' '.$version->release,
                $known,
            ));
        }

        return 'Same release ('.$known[0]->release.'), different patch builds.';
    }

    private function parseStamp(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            $utc = new DateTimeZone('UTC');

            return (new DateTimeImmutable($value, $utc))->setTimezone($utc);
        } catch (Throwable) {
            return null;
        }
    }

    private static function startsWith(string $value, string $prefix): bool
    {
        return stripos($value, $prefix) === 0;
    }
}
