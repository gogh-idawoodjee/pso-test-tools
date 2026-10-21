<?php

namespace App\Filament\Widgets;

use App\Support\PsoServices\PsoServicesRoutesClient;
use App\Support\PsoServices\PsoServicesRoutesException;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** The shared Google Routes cache in pso-services, as a few plain sentences (counts and dates only). */
class GoogleRoutesCache extends Widget
{
    public const CACHE_KEY = 'google_routes.stats';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.google-routes-cache';

    public function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array{rows: string, sources: string, added: string, newest: string, modes: ?string}|null null when pso-services could not be reached */
    public function summary(): ?array
    {
        $stats = $this->stats();
        if ($stats === null) {
            return null;
        }

        $bySource = is_array($stats['by_source'] ?? null) ? $stats['by_source'] : [];
        $byMode = is_array($stats['by_mode'] ?? null) ? $stats['by_mode'] : [];
        $newest = data_get($stats, 'fetched.newest');

        return [
            'rows' => number_format((int) $stats['rows']),
            'sources' => number_format((int) ($bySource['seeded'] ?? 0)).' seeded from the script\'s cache, '.number_format((int) ($bySource['google'] ?? 0)).' fetched from Google',
            'added' => number_format((int) ($stats['added_last_7_days'] ?? 0)).' added in the last 7 days',
            'newest' => is_string($newest) && $newest !== '' ? 'Newest answer '.$this->date($newest) : 'No live Google answers yet',
            'modes' => count($byMode) > 1
                ? collect($byMode)->map(static fn (mixed $count, string $mode): string => $mode.': '.number_format((int) $count))->implode(', ')
                : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function stats(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $stats = app(PsoServicesRoutesClient::class)->stats();
        } catch (PsoServicesRoutesException) {
            return null;
        }

        Cache::put(self::CACHE_KEY, $stats, 300);

        return $stats;
    }

    private function date(string $iso): string
    {
        try {
            return CarbonImmutable::parse($iso)->utc()->format('Y-m-d');
        } catch (Throwable) {
            return $iso;
        }
    }
}
