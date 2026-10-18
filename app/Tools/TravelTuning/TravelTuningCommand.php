<?php

namespace App\Tools\TravelTuning;

use App\Tools\LoadAudit\PsoToolsCheckout;

/**
 * The argument list for travel_tuning.py. Pure: no process, no filesystem. Text options go as --flag=value so a value
 * that starts with a dash is not read as a flag; the web never passes --offline or --review-csv (spec 4.2).
 */
class TravelTuningCommand
{
    public const MANIFEST = 'manifest.csv';

    public const NOTES = 'notes.md';

    public const OUT_HTML = 'travel_tuning.html';

    public const OUT_JSON = 'travel_tuning.json';

    public const TILES = ['esri-gray' => 'Esri grey', 'esri' => 'Esri streets', 'osm' => 'OpenStreetMap', 'none' => 'No map tiles'];

    /** option key => [flag, the script's default] */
    public const NUMBERS = [
        'review_minutes' => ['--review-minutes', 20.0],
        'small_minutes' => ['--small-minutes', 5.0],
        'model_tol_min' => ['--model-tol-min', 1.0],
        'model_tol_pct' => ['--model-tol-pct', 5.0],
        'long_leg_km' => ['--long-leg-km', 300.0],
    ];

    private const TEXT = ['title' => '--title', 'client' => '--client', 'dataset' => '--dataset', 'region_label' => '--region-label'];

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public static function build(PsoToolsCheckout $checkout, string $workDir, array $options, string $generated, bool $withNotes): array
    {
        $dir = rtrim($workDir, '/');
        $argv = [
            $checkout->python(), $checkout->travelTuningScript(),
            '--manifest', $dir.'/'.self::MANIFEST, '--out', $dir.'/'.self::OUT_HTML, '--json', $dir.'/'.self::OUT_JSON,
            '--template', $checkout->travelTuningTemplate(), '--generated', $generated,
        ];

        foreach (self::TEXT as $key => $flag) {
            $text = self::text($options[$key] ?? null);
            // The label is always passed, so the page does not depend on the script's own default.
            if ($key === 'region_label' && $text === '') {
                $text = 'Region';
            }
            if ($text !== '') {
                $argv[] = $flag.'='.$text;
            }
        }
        foreach (self::NUMBERS as $key => [$flag, $default]) {
            $value = $options[$key] ?? null;
            $argv[] = $flag.'='.self::number(is_numeric($value) && (float) $value >= 0 ? (float) $value : $default);
        }
        $tiles = (string) ($options['tiles'] ?? '');
        $argv[] = '--tiles='.(array_key_exists($tiles, self::TILES) ? $tiles : 'esri-gray');

        if ($withNotes) {
            array_push($argv, '--notes', $dir.'/'.self::NOTES);
        }

        return $argv;
    }

    /** One line, at most 120 characters. */
    private static function text(mixed $value): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : '')), 0, 120);
    }

    /** 20.0 is "20", 12.5 is "12.5". */
    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
