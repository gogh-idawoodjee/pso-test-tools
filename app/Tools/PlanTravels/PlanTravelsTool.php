<?php

namespace App\Tools\PlanTravels;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\PsoServices\PsoServicesRoutesClient;
use App\Support\PsoServices\PsoServicesRoutesException;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolFailedWithResult;
use App\Tools\Contracts\ToolResult;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ScriptRun;
use App\Tools\ToolContext;
use App\Tools\UnfiledLabel;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Runs pso-tools/plan-travels/plan_travels.py on a plan export (the legs), optionally checks each distinct
 * trip with Google Routes through pso-services, merges the answers back in with the script, and keeps the
 * page and the CSV (legs.json is not stored: the page carries the same data and the JSON download reads it
 * back from there, see EmbeddedPageData). Google (or the merge) failing after the build fails the run but
 * keeps the legs.
 *
 * Only the warnings that name no resource, shift or activity reach ToolRun.warnings (an unencrypted column);
 * the full list stays on the page, inside the encrypted result.
 */
class PlanTravelsTool implements Tool
{
    public const CHUNK = 200;

    public const TRAVEL_MODES = ['DRIVE' => 'Driving', 'TWO_WHEELER' => 'Two-wheeler', 'BICYCLE' => 'Bicycle', 'WALK' => 'Walking', 'TRANSIT' => 'Public transport'];

    public const ROUTING_PREFERENCES = ['TRAFFIC_UNAWARE' => 'Ignore traffic (as PSO does)', 'TRAFFIC_AWARE' => 'Live traffic', 'TRAFFIC_AWARE_OPTIMAL' => 'Live traffic, best route'];

    /** Script warnings that carry no resource, shift or activity id, so they may be stored unencrypted verbatim. */
    private const PLAIN_WARNING_PREFIXES = ['The export has no Plan_Travel rows', 'No Plan_Travel rows start on'];

    /** pso-services stopped calling Google (key or quota error, or its time budget) before these trips. */
    private const NOT_ATTEMPTED = 'Not attempted';

    private const TIME_LIMIT_ERROR = "Not attempted: the run's time limit was reached";

    /** When the Google step must stop sending chunks, so the merge still runs inside RunToolJob's 900 s. */
    private ?Carbon $googleDeadline = null;

    /** The Google fields' defaults, also set when the toggle is switched on (a hidden field keeps no default). */
    private const GOOGLE_DEFAULTS = ['travel_mode' => 'DRIVE', 'routing_preference' => 'TRAFFIC_UNAWARE', 'review_minutes' => 20, 'review_pct' => 0];

    public function __construct(private PsoServicesRoutesClient $routes) {}

    public static function id(): ToolId
    {
        return ToolId::PlanTravels;
    }

    public function requiredKinds(): array
    {
        return [ExportKind::Plan];
    }

    public function optionalKinds(): array
    {
        return [];
    }

    public function optionFields(): array
    {
        $google = static fn (Get $get): bool => (bool) $get('options.google');

        return [
            DatePicker::make('options.first_date')
                ->label('First date')
                ->helperText('The first day of planned travels to show. Filled in from the plan export when it says which day it is for.')
                ->native(false)
                ->required()
                ->rule('date'),
            TextInput::make('options.days')
                ->label('Days')
                ->helperText('How many days from the first date, 1 to 31.')
                ->integer()
                ->minValue(1)
                ->maxValue(31)
                ->default(1)
                ->required(),
            Toggle::make('options.google')
                ->label('Check against Google Routes')
                ->helperText('Costs one Google call for each trip that is not already in the shared cache on pso-services.')
                ->default(false)
                ->live()
                ->afterStateUpdated(static function (?bool $state, Get $get, Set $set): void {
                    if (! $state) {
                        return;
                    }
                    foreach (self::GOOGLE_DEFAULTS as $key => $value) {
                        if (blank($get("options.{$key}"))) {
                            $set("options.{$key}", $value);
                        }
                    }
                }),
            Select::make('options.travel_mode')
                ->label('Travel mode')
                ->options(self::TRAVEL_MODES)
                ->default('DRIVE')
                ->native(false)
                ->required()
                ->visible($google),
            Select::make('options.routing_preference')
                ->label('Routing preference')
                ->options(self::ROUTING_PREFERENCES)
                ->default('TRAFFIC_UNAWARE')
                ->native(false)
                ->required()
                ->visible($google),
            TextInput::make('options.review_minutes')
                ->label('Review when PSO and Google differ by at least (minutes)')
                ->numeric()
                ->minValue(0)
                ->default(20)
                ->visible($google),
            TextInput::make('options.review_pct')
                ->label('and by at least (percent)')
                ->helperText('0 means the minutes alone decide.')
                ->numeric()
                ->minValue(0)
                ->maxValue(1000)
                ->default(0)
                ->visible($google),
        ];
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        $checkout = PsoToolsCheckout::fromConfig();
        $workDir = dirname($inputPaths['plan']);
        $firstDate = $this->firstDate($context, $options);
        $days = max(1, min(31, (int) ($options['days'] ?? 1)));
        $timeout = (int) config('tools.plan_travels.timeout_seconds', 600);
        $this->googleDeadline = Carbon::now()->addSeconds((int) config('tools.plan_travels.google_deadline_seconds', 720));

        $context->reportProgress('Building legs', 0, null, 'build');
        $datasetName = $context->settings?->displayName ?: ($context->scope instanceof Dataset ? $context->scope->name : UnfiledLabel::for($context, ExportKind::Plan));
        $argv = PlanTravelsCommand::build($checkout, $workDir, $inputPaths['plan'], $firstDate, $days, $datasetName);
        ScriptRun::execute($context, $workDir, $argv, $timeout, 'Plan travels', progressLabel: 'Building legs');
        $built = $this->readOutputs($workDir);

        if (! (bool) ($options['google'] ?? false)) {
            return $this->result($built, $firstDate, $days, null);
        }

        $mode = array_key_exists((string) ($options['travel_mode'] ?? ''), self::TRAVEL_MODES) ? (string) $options['travel_mode'] : 'DRIVE';
        $preference = array_key_exists((string) ($options['routing_preference'] ?? ''), self::ROUTING_PREFERENCES) ? (string) $options['routing_preference'] : 'TRAFFIC_UNAWARE';

        try {
            [$google, $counts, $unchecked] = $this->checkWithGoogle($context, TripKeys::trips($built['data']['legs'] ?? []), $mode, $preference);
        } catch (PsoServicesRoutesException $e) {
            $partial = $this->result($built, $firstDate, $days, null);

            throw new ToolFailedWithResult(
                'The Google Routes check did not finish: '.$e->getMessage(),
                new ToolResult($partial->result, [...$partial->warnings, 'Google Routes was not checked, so the legs have no Google columns: '.$e->getMessage()], $partial->summary),
            );
        }

        $context->reportProgress('Merging Google results', 95, null, 'merge');

        try {
            $json = json_encode((object) $google, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            if (file_put_contents($workDir.'/'.PlanTravelsCommand::GOOGLE_JSON, $json) === false) {
                throw new RuntimeException('Plan travels could not save the Google Routes answers for the merge.');
            }

            // The built page and CSV are in memory: removing them means a merge that writes nothing cannot pass for one
            // that did. legs.json stays, because merge-google reads it.
            @unlink($workDir.'/'.PlanTravelsCommand::OUT_HTML);
            @unlink($workDir.'/'.PlanTravelsCommand::OUT_CSV);
            $argv = PlanTravelsCommand::mergeGoogle($checkout, $workDir, filled($options['review_minutes'] ?? null) ? (float) $options['review_minutes'] : 20.0, filled($options['review_pct'] ?? null) ? (float) $options['review_pct'] : 0.0);
            ScriptRun::execute($context, $workDir, $argv, $timeout, 'Plan travels', progressLabel: 'Merging Google results');
            $merged = $this->readOutputs($workDir);
            unset($built);
        } catch (RuntimeException|JsonException $e) {
            $partial = $this->result($built, $firstDate, $days, null);

            throw new ToolFailedWithResult(
                'The Google Routes check did not finish: '.$e->getMessage(),
                new ToolResult($partial->result, [...$partial->warnings, 'The Google results could not be added to the legs, so the legs have no Google columns.'], $partial->summary),
            );
        }

        $extra = [];
        if ($unchecked > 0) {
            $extra[] = "Google Routes check stopped early: {$unchecked} ".Str::plural('trip', $unchecked).' '.($unchecked === 1 ? 'was' : 'were')." not checked before the run's time limit.";
        }
        if ($counts['error'] > 0 && $counts['error'] === count($google) && $counts['cache'] + $counts['google'] === 0) {
            foreach ($google as $answer) {
                if (isset($answer['error'])) {
                    $extra[] = 'Google answered no trips: '.$answer['error'];

                    break;
                }
            }
        }

        return $this->result($merged, $firstDate, $days, $counts, $extra);
    }

    /**
     * What may go into ToolRun.warnings, which is not encrypted: the "no Plan_Travel rows" warnings verbatim
     * (they name only dates), and one line counting the rest, which name resources, shifts and activities.
     *
     * @param  array<int, mixed>  $warnings  meta.warnings from legs.json
     * @return list<string>
     */
    public static function storedWarnings(array $warnings): array
    {
        $stored = [];
        $others = 0;
        foreach ($warnings as $warning) {
            if (! is_string($warning) || trim($warning) === '') {
                continue;
            }
            if (Str::startsWith(trim($warning), self::PLAIN_WARNING_PREFIXES)) {
                $stored[] = trim($warning);
            } else {
                $others++;
            }
        }
        if ($others > 0) {
            $stored[] = "{$others} travel fragment ".Str::plural('warning', $others).': open the page for details.';
        }

        return $stored;
    }

    /** The chosen first date, else the plan export's own date (its Input_Reference day in the dataset zone). */
    private function firstDate(ToolContext $context, array $options): string
    {
        $given = trim((string) ($options['first_date'] ?? ''));
        if ($given === '') {
            $fileId = $context->run->inputs['files'][ExportKind::Plan->value] ?? null;
            $given = (string) (ExportFile::withoutGlobalScopes()->find($fileId)?->exportDate($context->timeZone) ?? '');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $given) !== 1) {
            throw new RuntimeException('Choose the first date to show: the plan export does not say which day it is for.');
        }

        return substr($given, 0, 10);
    }

    /**
     * google.json's answers, the counts, and how many trips the deadline left unchecked.
     *
     * @param  array<string, array{origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}>  $trips
     * @return array{0: array<string, array<string, mixed>>, 1: array{cache: int, google: int, error: int}, 2: int}
     */
    private function checkWithGoogle(ToolContext $context, array $trips, string $mode, string $preference): array
    {
        // RunToolJob allows 900 s for the whole run: 1,781 legs with a cold cache is about 9 chunks of 200, each
        // allowed psott.routes.timeout. The deadline (tools.plan_travels.google_deadline_seconds) stops sending
        // chunks in time for the merge, so a slow Google step never loses the built legs.
        $unchecked = 0;
        $google = $this->computeInChunks($context, $trips, $mode, $preference, 'Checking trips with Google', 'google', $unchecked);

        // Trips pso-services did not attempt (it stopped after a key or quota error, or hit its time budget) go
        // once more; whatever comes back the second time is final. Skipped when the deadline already stopped us.
        $retry = array_filter($trips, static fn (string $key): bool => str_starts_with($google[$key]['error'] ?? '', self::NOT_ATTEMPTED), ARRAY_FILTER_USE_KEY);
        if ($retry !== [] && $unchecked === 0) {
            if ($this->timeIsShort()) {
                $unchecked = count($retry);
            } else {
                $google = array_replace($google, $this->computeInChunks($context, $retry, $mode, $preference, 'Retrying trips Google did not check', 'google-retry', $unchecked));
            }
        }

        // Counted from the final answers (pso-services counts a not-attempted trip as an error the first time).
        $counts = ['cache' => 0, 'google' => 0, 'error' => 0];
        foreach ($google as $answer) {
            $source = isset($answer['error']) ? 'error' : $answer['source'];
            if (isset($counts[$source])) {
                $counts[$source]++;
            }
        }

        return [$google, $counts, $unchecked];
    }

    /** True when less than the margin (the request timeout, at most 270 s) is left before the Google deadline. */
    private function timeIsShort(): bool
    {
        $left = ($this->googleDeadline ?? Carbon::now())->getTimestamp() - Carbon::now()->getTimestamp();

        // pso-services gives up after its own 240 s budget, so 270 s is enough margin whatever the client timeout.
        return $left < min((int) config('psott.routes.timeout', 300), 270);
    }

    /**
     * @param  array<string, array{origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}>  $trips
     * @param  int  $unchecked  increased by the trips left unsent because the deadline came
     * @return array<string, array<string, mixed>> route_key => the answer as google.json stores it
     */
    private function computeInChunks(ToolContext $context, array $trips, string $mode, string $preference, string $label, string $stage, int &$unchecked): array
    {
        $chunks = array_chunk($trips, self::CHUNK, true);
        $total = count($chunks);
        $google = [];

        foreach ($chunks as $i => $chunk) {
            if ($this->timeIsShort()) {
                foreach (array_slice($chunks, $i) as $left) {
                    foreach (array_keys($left) as $key) {
                        $google[(string) $key] = ['error' => self::TIME_LIMIT_ERROR];
                        $unchecked++;
                    }
                }

                break;
            }
            $number = $i + 1;
            $context->reportProgress("{$label}, {$number} of {$total}", intdiv($i * 100, $total), null, "{$stage}-{$number}");

            $legs = [];
            foreach ($chunk as $key => $trip) {
                $legs[] = ['id' => (string) $key, 'origin' => $trip['origin'], 'destination' => $trip['destination']];
            }
            $response = $this->routes->compute($legs, $mode, $preference);

            foreach ($response['results'] as $answer) {
                $id = (string) ($answer['id'] ?? '');
                if (! isset($chunk[$id])) {
                    continue;
                }
                $google[$id] = match (true) {
                    isset($answer['error']) => ['error' => (string) $answer['error']],
                    isset($answer['durationSec']) => ['durationSec' => (int) $answer['durationSec'], 'distanceKm' => (float) ($answer['distanceKm'] ?? 0), 'source' => (string) ($answer['source'] ?? '')],
                    default => ['error' => 'Unreadable answer from pso-services'],
                };
            }
            // So cache + google + error adds up to the trips sent.
            foreach (array_keys($chunk) as $key) {
                $google[(string) $key] ??= ['error' => 'No answer from pso-services for this trip'];
            }
        }

        return $google;
    }

    /** @return array{html: string, csv: string, data: array<string, mixed>} */
    private function readOutputs(string $workDir): array
    {
        $html = @file_get_contents($workDir.'/'.PlanTravelsCommand::OUT_HTML);
        $csv = @file_get_contents($workDir.'/'.PlanTravelsCommand::OUT_CSV);
        $json = @file_get_contents($workDir.'/'.PlanTravelsCommand::OUT_JSON);
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data) || $html === false || $html === '' || $csv === false) {
            throw new RuntimeException('Plan travels finished but wrote no results.');
        }

        return ['html' => $html, 'csv' => $csv, 'data' => $data];
    }

    /**
     * @param  array{html: string, csv: string, data: array<string, mixed>}  $outputs
     * @param  array{cache: int, google: int, error: int}|null  $google
     * @param  list<string>  $extraWarnings  added after the script's (already free of ids)
     */
    private function result(array $outputs, string $firstDate, int $days, ?array $google, array $extraWarnings = []): ToolResult
    {
        $legs = array_values(array_filter($outputs['data']['legs'] ?? [], 'is_array'));
        $flags = array_map(static fn (array $leg): string => (string) ($leg['flag'] ?? ''), $legs);
        $summary = [
            'leg_count' => count($legs),
            'resource_count' => count(array_unique(array_map(static fn (array $leg): string => (string) ($leg['resource_id'] ?? ''), $legs))),
            'review_count' => count(array_filter($flags, static fn (string $flag): bool => $flag === 'Review')),
            'missing_count' => count(array_filter($flags, static fn (string $flag): bool => str_starts_with($flag, 'Missing'))),
            'first_date' => $firstDate,
            'last_date' => Carbon::parse($firstDate)->addDays($days - 1)->toDateString(),
            'days' => $days,
            'google' => $google,
        ];
        $summary['line'] = self::line($summary);

        $warnings = $outputs['data']['meta']['warnings'] ?? [];

        // The page embeds the same JSON; storing legs.json too made a 5-day SaskTel run 9.9 MB encrypted (#40).
        return new ToolResult(result: ['html' => $outputs['html'], 'csv' => $outputs['csv']], warnings: [...self::storedWarnings(is_array($warnings) ? $warnings : []), ...$extraWarnings], summary: $summary);
    }

    /** "6 legs for 5 resources, 2026-10-05 to 2026-10-09; Google: 2 trips from the cache, 1 fetched and 1 without an answer" */
    public static function line(array $summary): string
    {
        $line = number_format($summary['leg_count']).' '.Str::plural('leg', $summary['leg_count'])
            .' for '.number_format($summary['resource_count']).' '.Str::plural('resource', $summary['resource_count']).', '
            .($summary['first_date'] === $summary['last_date'] ? $summary['first_date'] : "{$summary['first_date']} to {$summary['last_date']}");

        if ($summary['google'] !== null) {
            $line .= '; Google: '.self::googleCounts($summary['google']);
        }

        return $line;
    }

    /** Counts by trip, zero counts left out: "all 3,319 trips from the cache", "233 trips from the cache and 5 fetched". */
    private static function googleCounts(array $g): string
    {
        $total = $g['cache'] + $g['google'] + $g['error'];
        if ($total === 0) {
            return 'no trips to check';
        }
        if ($g['cache'] === $total) {
            return ($total > 1 ? 'all ' : '').number_format($total).' '.Str::plural('trip', $total).' from the cache';
        }

        $parts = array_filter([
            'from the cache' => $g['cache'],
            'fetched' => $g['google'],
            'without an answer' => $g['error'],
        ]);
        $phrases = [];
        foreach ($parts as $what => $count) {
            // The first count carries the noun: "8 trips fetched and 2 without an answer".
            $phrases[] = number_format($count).($phrases === [] ? ' '.Str::plural('trip', $count) : '').' '.$what;
        }

        return Arr::join($phrases, ', ', ' and ');
    }
}
