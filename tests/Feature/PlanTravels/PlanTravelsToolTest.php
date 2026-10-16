<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\PlanTravelsEntry;
use App\Filament\Widgets\ToolCatalogue;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\Contracts\ToolFailedWithResult;
use App\Tools\PlanTravels\PlanTravelsTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakePlanEnv(string $name, ?string $value): void
{
    if ($value === null) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        return;
    }
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv("{$name}={$value}");
}

/** @return list<array<string, mixed>> one entry per script call the stub recorded */
function planCalls(string $file): array
{
    return is_file($file) ? array_map(static fn (string $line): array => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
}

/** pso-services stand-in: origin latitude 50.0 has no route, below 51 is a cache hit, the rest come from Google. */
function routesFake(?int $status = null): array
{
    return ['https://pso-services.test/api/v2/routes/compute' => static function (Request $request) use ($status) {
        if ($status !== null) {
            return Http::response(['message' => 'Google Routes is not configured on pso-services.'], $status);
        }
        $results = [];
        $counts = ['cache' => 0, 'google' => 0, 'error' => 0];
        foreach ($request->data()['legs'] as $leg) {
            if ($leg['origin']['lat'] == 50.0) {
                $results[] = ['id' => $leg['id'], 'error' => 'No route returned'];
                $counts['error']++;

                continue;
            }
            $source = $leg['origin']['lat'] < 51 ? 'cache' : 'google';
            $results[] = ['id' => $leg['id'], 'durationSec' => $source === 'google' ? 4374 : 700, 'distanceKm' => 117.04, 'source' => $source];
            $counts[$source]++;
        }

        return Http::response(['results' => $results, 'counts' => $counts]);
    }];
}

beforeEach(function () {
    config([
        'tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3', 'tools.plan_travels.timeout_seconds' => 600,
        'psott.pso-services-api' => 'pso-services.test', 'psott.pso-services-api-version' => 'v2',
    ]);
    $this->scratch = sys_get_temp_dir().'/pt-'.uniqid();
    mkdir($this->scratch);
    file_put_contents($this->scratch.'/plan.xml', '<dsScheduleData/>');
    $this->argsFile = $this->scratch.'/calls.jsonl';
    fakePlanEnv('FAKE_PLAN_ARGS_FILE', $this->argsFile);
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user)->state(['account_id' => 'SATE']))->create([
        'name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina'],
    ]);
    $this->plan = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-05']]);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::PlanTravels, 'result' => null, 'progress' => null, 'inputs' => ['files' => ['plan' => $this->plan->id], 'options' => []],
    ]);
    $this->context = new ToolContext($this->run, $this->dataset, $this->dataset->settings, 'America/Regina');
    $this->inputs = ['plan' => $this->scratch.'/plan.xml'];
    $this->actingAs($this->user);
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
    foreach (['FAKE_PLAN_ARGS_FILE', 'FAKE_PLAN_MODE', 'FAKE_PLAN_LEGS'] as $name) {
        fakePlanEnv($name, null);
    }
});

it('is registered and asks for a plan export only', function () {
    $tool = app(ToolRegistry::class)->for(ToolId::PlanTravels);

    expect($tool)->toBeInstanceOf(PlanTravelsTool::class)
        ->and($tool->requiredKinds())->toBe([ExportKind::Plan])
        ->and($tool->optionalKinds())->toBe([])
        ->and(PlanTravelsEntry::canAccess())->toBeTrue();
    Livewire::test(ToolCatalogue::class)->assertSee('Plan travels')->assertSee('Needs a Plan export');
});

it('offers the date, days, Google toggle, mode, preference and thresholds in that order', function () {
    $names = array_map(static fn ($field) => $field->getName(), app(PlanTravelsTool::class)->optionFields());

    expect($names)->toBe(['options.first_date', 'options.days', 'options.google', 'options.travel_mode', 'options.routing_preference', 'options.review_minutes', 'options.review_pct']);
});

it('builds the legs without Google and stores the page, CSV, data, summary and warnings', function () {
    Http::fake();

    $result = app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05', 'days' => 1, 'google' => false]);

    $calls = planCalls($this->argsFile);
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['argv'])->toContain('build', '--export', $this->scratch.'/plan.xml', '--date', '2026-10-05', '--days', '1', '--dataset-name', 'NORTH', '--out-dir', $this->scratch)
        ->and($calls[0]['progress_env'])->toBe('lines')
        ->and($result->result['html'])->toContain('Plan travels page without Google')
        ->and($result->result['csv'])->toStartWith("Resource ID,Flag\n")
        ->and($result->result)->toHaveKeys(['html', 'csv'])->not->toHaveKey('data')
        ->and($result->warnings)->toBe(['1 travel fragment warning: open the page for details.'])
        ->and($result->summary)->toBe([
            'leg_count' => 6, 'resource_count' => 5, 'review_count' => 0, 'missing_count' => 0,
            'first_date' => '2026-10-05', 'last_date' => '2026-10-05', 'days' => 1, 'google' => null,
            'line' => '6 legs for 5 resources, 2026-10-05',
        ]);
    Http::assertNothingSent();
});

it('uses the plan export date when no first date was given, and names the range for several days', function () {
    Http::fake();

    $result = app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['days' => 5]);

    expect(planCalls($this->argsFile)[0]['argv'])->toContain('--date', '2026-10-05', '--days', '5')
        ->and($result->summary['last_date'])->toBe('2026-10-09')
        ->and($result->summary['line'])->toBe('6 legs for 5 resources, 2026-10-05 to 2026-10-09');
});

it('fails in plain words when neither the options nor the export give a date', function () {
    $this->plan->update(['summary' => []]);

    expect(fn () => app(PlanTravelsTool::class)->run($this->context, $this->inputs, []))
        ->toThrow(RuntimeException::class, 'Choose the first date to show: the plan export does not say which day it is for.');
});

it('checks each distinct route key with Google once, writes google.json keyed by route key and merges', function () {
    Http::fake(routesFake());

    $result = app(PlanTravelsTool::class)->run($this->context, $this->inputs, [
        'first_date' => '2026-10-05', 'days' => 1, 'google' => true, 'travel_mode' => 'DRIVE', 'routing_preference' => 'TRAFFIC_UNAWARE', 'review_minutes' => 20, 'review_pct' => 0,
    ]);

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request) => $request['travelMode'] === 'DRIVE'
        && $request['routingPreference'] === 'TRAFFIC_UNAWARE'
        && count($request['legs']) === 4
        && $request['legs'][0] === ['id' => '52.123457,-106.500000|52.200000,-106.600000', 'origin' => ['lat' => 52.123457, 'lng' => -106.5], 'destination' => ['lat' => 52.2, 'lng' => -106.6]]);

    $calls = planCalls($this->argsFile);
    expect($calls)->toHaveCount(2)
        ->and($calls[1]['argv'])->toContain('merge-google', '--legs', $this->scratch.'/legs.json', '--google', $this->scratch.'/google.json', '--review-minutes', '20', '--review-pct', '0')
        ->and($calls[1]['google'])->toBe([
            '52.123457,-106.500000|52.200000,-106.600000' => ['durationSec' => 4374, 'distanceKm' => 117.04, 'source' => 'google'],
            '50.000000,-106.000000|51.000000,-106.100000' => ['error' => 'No route returned'],
            '50.001000,-106.000000|51.001000,-106.100000' => ['durationSec' => 700, 'distanceKm' => 117.04, 'source' => 'cache'],
            '50.002000,-106.000000|51.002000,-106.100000' => ['durationSec' => 700, 'distanceKm' => 117.04, 'source' => 'cache'],
        ])
        ->and($result->result['html'])->toContain('Plan travels page with Google')
        ->and($result->summary)->toBe([
            'leg_count' => 6, 'resource_count' => 5, 'review_count' => 2, 'missing_count' => 1,
            'first_date' => '2026-10-05', 'last_date' => '2026-10-05', 'days' => 1, 'google' => ['cache' => 2, 'google' => 1, 'error' => 1],
            'line' => '6 legs for 5 resources, 2026-10-05; Google, by trip: 2 from the cache, 1 fetched, 1 without a Google answer',
        ]);
});

it('posts 450 distinct trips in chunks of 200, 200 and 50 and reports each chunk', function () {
    fakePlanEnv('FAKE_PLAN_LEGS', '449');
    Http::fake(routesFake());
    $labels = [];
    $this->run::updating(static function ($run) use (&$labels): void {
        $labels[] = $run->progress['label'] ?? null;
    });

    app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05', 'google' => true]);

    $sizes = array_map(static fn (array $pair): int => count($pair[0]['legs']), Http::recorded()->all());
    $ids = collect(Http::recorded())->flatMap(static fn (array $pair) => array_column($pair[0]['legs'], 'id'));
    expect($sizes)->toBe([200, 200, 50])
        ->and($ids->unique()->count())->toBe(450)
        ->and($labels)->toContain('Building legs', 'Building legs: 0%', 'Checking trips with Google, 1 of 3', 'Checking trips with Google, 2 of 3', 'Checking trips with Google, 3 of 3', 'Merging Google results')
        // merge-google emits no progress lines (the real script never does), so its label carries no percent.
        ->and(collect($labels)->filter(static fn (?string $label): bool => str_starts_with((string) $label, 'Merging Google results: ')))->toBeEmpty()
        // The script's only stage is "reading"; its shared label names the audit messages, which would be wrong here.
        ->and(collect($labels)->filter(static fn (?string $label): bool => str_contains((string) $label, 'audit messages')))->toBeEmpty();
});

it('fails with the pso-services message and keeps the legs without Google', function () {
    Http::fake(routesFake(503));

    try {
        app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05', 'google' => true]);
        $this->fail('expected the run to fail');
    } catch (ToolFailedWithResult $e) {
        expect($e->getMessage())->toBe('The Google Routes check did not finish: Google Routes is not configured on pso-services.')
            ->and($e->partial->result['html'])->toContain('Plan travels page without Google')
            ->and($e->partial->result)->not->toHaveKey('data')
            ->and($e->partial->warnings)->toBe(['1 travel fragment warning: open the page for details.', 'Google Routes was not checked, so the legs have no Google columns: Google Routes is not configured on pso-services.'])
            ->and($e->partial->summary['google'])->toBeNull()
            ->and(planCalls($this->argsFile))->toHaveCount(1);
    }
});

it('names the script stage when the build fails, and refuses a build that wrote nothing', function (string $mode, string $message) {
    fakePlanEnv('FAKE_PLAN_MODE', $mode);

    expect(fn () => app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05']))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'stopped' => ['fail', 'Plan travels stopped while reading the export: the root element is Foo, not dsScheduleData'],
    'silent' => ['silent', 'Plan travels finished but wrote no results.'],
]);

it('keeps the script warnings out of the unencrypted warnings column, except the no-rows ones', function () {
    $warnings = [
        'The export has no Plan_Travel rows, so there are no legs to show.',
        'Resource R1, shift S1: 2 fragments could not be merged before activity A1 at 08:00.',
        'No Plan_Travel rows start on 2026-10-05 to 2026-10-05; the export covers 2026-10-06 to 2026-10-08.',
        'Resource R2, shift S2: travel to activity A2 has no end coordinates.',
        '  ',
        42,
    ];

    expect(PlanTravelsTool::storedWarnings($warnings))->toBe([
        'The export has no Plan_Travel rows, so there are no legs to show.',
        'No Plan_Travel rows start on 2026-10-05 to 2026-10-05; the export covers 2026-10-06 to 2026-10-08.',
        '2 travel fragment warnings: open the page for details.',
    ])
        ->and(PlanTravelsTool::storedWarnings([]))->toBe([])
        ->and(PlanTravelsTool::storedWarnings(['The export has no Plan_Travel rows.']))->toBe(['The export has no Plan_Travel rows.']);
});

it('keeps the built legs when merge-google fails or writes nothing after the Google check', function (string $mode, string $message) {
    // The stub applies FAKE_PLAN_MODE to both commands: the pso-services stand-in sets it, so only the merge sees it.
    $routes = routesFake()['https://pso-services.test/api/v2/routes/compute'];
    Http::fake(['https://pso-services.test/api/v2/routes/compute' => static function (Request $request) use ($routes, $mode) {
        fakePlanEnv('FAKE_PLAN_MODE', $mode);

        return $routes($request);
    }]);

    try {
        app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05', 'google' => true]);
        $this->fail('expected the run to fail');
    } catch (ToolFailedWithResult $e) {
        $calls = planCalls($this->argsFile);
        expect($e->getMessage())->toBe('The Google Routes check did not finish: '.$message)
            ->and($calls)->toHaveCount(2)
            ->and($calls[1]['argv'][0])->toBe('merge-google')
            ->and($e->partial->result['html'])->toContain('Plan travels page without Google')
            ->and($e->partial->result['csv'])->toStartWith("Resource ID,Flag\n")
            ->and($e->partial->result)->not->toHaveKey('data')
            ->and($e->partial->warnings)->toBe(['1 travel fragment warning: open the page for details.', 'The Google results could not be added to the legs, so the legs have no Google columns.'])
            ->and($e->partial->summary['google'])->toBeNull()
            ->and($e->partial->summary['leg_count'])->toBe(6);
    }
})->with([
    'stopped' => ['fail', 'Plan travels stopped while merging the Google results: the Google file is not what the build expects'],
    'silent' => ['silent', 'Plan travels finished but wrote no results.'],
]);

it('sends the trips pso-services did not attempt once more, and takes the second answer', function () {
    $missed = '50.001000,-106.000000|51.001000,-106.100000';
    $routes = routesFake()['https://pso-services.test/api/v2/routes/compute'];
    $attempt = 0;
    Http::fake(['https://pso-services.test/api/v2/routes/compute' => static function (Request $request) use ($routes, $missed, &$attempt) {
        $attempt++;
        $response = $routes($request);
        if ($attempt > 1) {
            return $response;
        }
        $body = json_decode((string) $response->wait()->getBody(), true);
        foreach ($body['results'] as $i => $result) {
            if ($result['id'] === $missed) {
                $body['results'][$i] = ['id' => $missed, 'error' => 'Not attempted: HTTP 429'];
            }
        }

        return Http::response($body);
    }]);

    $result = app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05', 'google' => true]);

    $sent = array_map(static fn (array $pair): array => array_column($pair[0]['legs'], 'id'), Http::recorded()->all());
    expect($sent)->toHaveCount(2)
        ->and($sent[0])->toHaveCount(4)
        ->and($sent[1])->toBe([$missed])
        ->and(planCalls($this->argsFile)[1]['google'][$missed])->toBe(['durationSec' => 700, 'distanceKm' => 117.04, 'source' => 'cache'])
        ->and($result->summary['google'])->toBe(['cache' => 2, 'google' => 1, 'error' => 1]);
});

it('keeps a trip that is still not attempted the second time as an error', function () {
    Http::fake(['https://pso-services.test/api/v2/routes/compute' => static fn (Request $request) => Http::response([
        'results' => array_map(static fn (array $leg): array => ['id' => $leg['id'], 'error' => 'Not attempted: time budget reached'], $request->data()['legs']),
        'counts' => ['cache' => 0, 'google' => 0, 'error' => count($request->data()['legs'])],
    ])]);

    $result = app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05', 'google' => true]);

    Http::assertSentCount(2);
    expect($result->summary['google'])->toBe(['cache' => 0, 'google' => 0, 'error' => 4])
        ->and(planCalls($this->argsFile)[1]['google'])->toHaveCount(4)
        ->and(array_column(planCalls($this->argsFile)[1]['google'], 'error'))->each->toBe('Not attempted: time budget reached');
});

it('stops calling pso-services when too little of the run time is left, and still merges', function () {
    config(['tools.plan_travels.google_deadline_seconds' => 600, 'psott.routes.timeout' => 300]);
    fakePlanEnv('FAKE_PLAN_LEGS', '449');
    $routes = routesFake()['https://pso-services.test/api/v2/routes/compute'];
    Http::fake(['https://pso-services.test/api/v2/routes/compute' => static function (Request $request) use ($routes) {
        // The first chunk takes 340 s: 260 s are left, under the 270 s margin.
        Carbon\Carbon::setTestNow(Carbon\Carbon::now()->addSeconds(340));

        return $routes($request);
    }]);

    try {
        $result = app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05', 'google' => true]);
    } finally {
        Carbon\Carbon::setTestNow();
    }

    $calls = planCalls($this->argsFile);
    $unchecked = array_filter($calls[1]['google'], static fn (array $answer): bool => ($answer['error'] ?? null) === "Not attempted: the run's time limit was reached");
    Http::assertSentCount(1);
    expect($calls)->toHaveCount(2)
        ->and($calls[1]['google'])->toHaveCount(450)
        ->and($unchecked)->toHaveCount(250)
        ->and($result->summary['google']['error'])->toBe(250 + count(array_filter($calls[1]['google'], static fn (array $answer): bool => ($answer['error'] ?? null) === 'No route returned')))
        ->and($result->warnings)->toContain("Google Routes check stopped early: 250 trips were not checked before the run's time limit.");
});

it('skips the retry pass when the time left is short', function () {
    config(['tools.plan_travels.google_deadline_seconds' => 600, 'psott.routes.timeout' => 300]);
    $missed = '50.001000,-106.000000|51.001000,-106.100000';
    Http::fake(['https://pso-services.test/api/v2/routes/compute' => static function (Request $request) use ($missed) {
        Carbon\Carbon::setTestNow(Carbon\Carbon::now()->addSeconds(340));

        return Http::response(['results' => array_map(static fn (array $leg): array => $leg['id'] === $missed
            ? ['id' => $leg['id'], 'error' => 'Not attempted: HTTP 429']
            : ['id' => $leg['id'], 'durationSec' => 700, 'distanceKm' => 1.5, 'source' => 'cache'], $request->data()['legs']), 'counts' => []]);
    }]);

    try {
        $result = app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05', 'google' => true]);
    } finally {
        Carbon\Carbon::setTestNow();
    }

    Http::assertSentCount(1);
    expect(planCalls($this->argsFile)[1]['google'][$missed])->toBe(['error' => 'Not attempted: HTTP 429'])
        ->and($result->summary['google'])->toBe(['cache' => 3, 'google' => 0, 'error' => 1])
        ->and($result->warnings)->toContain("Google Routes check stopped early: 1 trip was not checked before the run's time limit.");
});

it('adds one warning with the first error text when Google answered no trip at all', function () {
    Http::fake(['https://pso-services.test/api/v2/routes/compute' => static fn (Request $request) => Http::response([
        'results' => array_map(static fn (array $leg): array => ['id' => $leg['id'], 'error' => 'API key not valid'], $request->data()['legs']),
        'counts' => [],
    ])]);

    $result = app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05', 'google' => true]);

    expect($result->summary['google'])->toBe(['cache' => 0, 'google' => 0, 'error' => 4])
        ->and(array_values(array_filter($result->warnings, static fn (string $w): bool => str_starts_with($w, 'Google answered'))))->toBe(['Google answered no trips: API key not valid']);
});

it('writes an error for an unreadable answer and for a trip pso-services left out', function () {
    Http::fake(['https://pso-services.test/api/v2/routes/compute' => static fn (Request $request) => Http::response([
        'results' => [
            ['id' => $request->data()['legs'][0]['id'], 'source' => 'cache'],
            ['id' => $request->data()['legs'][1]['id'], 'durationSec' => 700, 'distanceKm' => 1.5, 'source' => 'cache'],
        ],
        'counts' => [],
    ])]);

    $result = app(PlanTravelsTool::class)->run($this->context, $this->inputs, ['first_date' => '2026-10-05', 'google' => true, 'review_minutes' => '', 'review_pct' => '']);

    $calls = planCalls($this->argsFile);
    $errors = array_count_values(array_map(static fn (array $answer): string => $answer['error'] ?? 'ok', $calls[1]['google']));
    expect($errors)->toBe(['Unreadable answer from pso-services' => 1, 'ok' => 1, 'No answer from pso-services for this trip' => 2])
        ->and($result->summary['google'])->toBe(['cache' => 1, 'google' => 0, 'error' => 3])
        ->and($calls[1]['argv'])->toContain('--review-minutes', '20', '--review-pct', '0');
});

it('names an unfiled run after the plan export file', function () {
    Http::fake();
    $plan = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Plan, 'original_filename' => 'north_plan_1005.xml', 'summary' => ['export_date' => '2026-10-05']]);
    $run = ToolRun::factory()->for($this->user, 'scope')->create([
        'tool' => ToolId::PlanTravels, 'result' => null, 'progress' => null, 'inputs' => ['files' => ['plan' => $plan->id], 'options' => []],
    ]);

    app(PlanTravelsTool::class)->run(new ToolContext($run, $this->user, null, 'America/Regina'), $this->inputs, ['first_date' => '2026-10-05', 'days' => 1, 'google' => false]);

    $argv = planCalls($this->argsFile)[0]['argv'];
    expect($argv[array_search('--dataset-name', $argv, true) + 1])->toBe('north_plan_1005.xml');
});
