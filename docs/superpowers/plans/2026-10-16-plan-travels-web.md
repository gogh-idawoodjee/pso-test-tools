# Plan travels (web) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A dataset tool, "Plan travels", that runs pso-tools `plan-travels/plan_travels.py` on a plan export, optionally checks every distinct trip against Google Routes through pso-services, and shows the script's page in the run with a CSV and JSON download, plus a "Check in PSO" link that opens the Travel Analyzer prefilled.

**Architecture:** `PlanTravelsTool` (Tool contract) runs `plan_travels.py build` as a child process through `ScriptRun` (the activity trace pattern). With Google on it collects the distinct `route_key` values from `legs.json`, splits each into the request's origin and destination, posts them in chunks of 200 to pso-services `POST /api/v2/routes/compute` through a new `PsoServicesRoutesClient`, writes `google.json` keyed by those `route_key`s verbatim and runs `plan_travels.py merge-google`. A pso-services failure fails the run but keeps the build output, through a new `ToolFailedWithResult` exception that `RunToolJob` stores before marking the run failed. The run page shows the page in the sandboxed iframe; a new `tool-runs.download-csv` route serves the CSV.

**Tech Stack:** Laravel 13, Filament v5 (forms: `DatePicker`, `TextInput`, `Toggle`, `Select`, `visible()` with `Get`), Livewire 4, Pest 4, `Http::fake`.

**Spec:** `docs/superpowers/specs/2026-10-16-plan-travels-design.md` (section 6 is this plan; 3, 4.1, 4.3, 5.3, 7 and 8 are the interfaces consumed). Behaviour reference: `docs/superpowers/notes/2026-10-16-plan-travels-inventory.md`.

One repository: pso-test-tools (`/Users/idawoodjee/Herd/pso-test-tools`), branch `feat/plan-travels` from main. It runs after the pso-tools plan (the script) and the pso-services plan (the endpoint); every test here uses a stub script and `Http::fake`, so the work does not wait on either.

## Global Constraints

- Filament v5 APIs only (confirmed with Context7 `/websites/filamentphp_5_x`: `Toggle::make()->live()`, `Select::make()->options()->default()`, `DatePicker::make()->native(false)`, `->visible(fn (Get $get): bool => ...)` with `Filament\Schemas\Components\Utilities\Get`, `Page::getUrl([...])` puts extra parameters in the query string). Ids are UUID strings. Run `vendor/bin/pint --dirty` before every commit; `php artisan test --compact` before each commit (the known `ExampleTest` failure is the only one allowed).
- Contract with the pso-tools plan (`docs/superpowers/plans/2026-10-16-plan-travels-python.md`, Interfaces): `plan_travels.py build --export <file> --date YYYY-MM-DD --days N --out-dir <dir> [--dataset-name <name>] [--template <html>]` and `plan_travels.py merge-google --legs <legs.json> --google <google.json> --out-dir <dir> [--template <html>] [--review-minutes M] [--review-pct P]`; both write `legs.json`, `plan_travels.csv`, `plan_travels.html` in the out dir. `legs.json` is `{"meta": {...}, "legs": [...]}`. The web reads only `meta.warnings` (list of strings) and, per leg, `resource_id`, `flag` and `route_key` (`"<oLat>,<oLng>|<dLat>,<dLng>"`, six decimals, null when any coordinate is missing); every count in the summary is computed here from the legs, so a `meta` key the script renames does not break the run. `--dataset-name` gets the dataset's display name (else its name) for the page header. Both commands emit only `PROGRESS {"stage":"reading","percent":N}`; the web labels progress by the command it is running ("Building legs", "Merging Google results") through a new optional `progressLabel` argument to `ScriptRun::execute`, never by stage name. Exit 1 with a `Stopped during:` line on failure.
- `google.json` (spec 6.3 step 3): an object keyed by each distinct leg `route_key`, copied verbatim from `legs.json` (the web never formats a coordinate itself; it only splits the key on `|` and `,` to build the pso-services request id, origin and destination), each value `{"durationSec": int, "distanceKm": float, "source": "cache"|"google"}` or `{"error": string}`. Legs whose `route_key` is null or does not split into four in-range numbers are not sent and not keyed; merge-google marks those legs itself.
- pso-services request and response exactly as spec 5.3. The tool never sends a Google key (spec 7). No production PSO URL is involved.
- Plain English for users, "Allocated" never "placed", no em dashes in code, labels or messages. Summary (plain JSON) holds counts and dates only, never resource names or activity ids; leg data lives only in the encrypted `result`.

## Review Focus

1. The google.json key must be the leg's `route_key` byte for byte (merge-google matches on it verbatim), so the web copies it and never reformats coordinates. (Task 2 test `it copies route keys verbatim and splits them for the request`.)
2. Chunking at 200: 450 distinct trips must go as 200, 200 and 50 with one progress label per chunk, and a duplicate pair or a leg without coordinates must never be sent. (Task 4 test `it posts 450 distinct trips in chunks of 200, 200 and 50 and reports each chunk`.)
3. A 503 from pso-services mid-run must fail the run with pso-services' own message and still store the build's page, CSV and data, with a warning saying the legs have no Google columns. (Task 4 test `it fails with the pso-services message and keeps the legs without Google`, Task 3 test `it keeps the partial result of a run that failed after building`.)
4. "Check in PSO" opens from a sandboxed iframe: without `allow-popups-to-escape-sandbox` on both the iframe and the page's CSP the new tab inherits the sandbox and the Travel Analyzer cannot use its session. Only the plan travels page gets the extra token. (Task 6 tests `it serves the plan travels page with links that can leave the sandbox` and `it keeps the other tools on the unchanged sandbox`.)
5. The Travel Analyzer prefill must take all four coordinates or none, and refuse out-of-range or non-numeric values. (Task 7 test `it ignores the query when a value is missing, out of range or not a number`.)

---

## File structure

Create:
- `app/Tools/PlanTravels/PlanTravelsCommand.php` argv for `build` and `merge-google`, output file names.
- `app/Tools/PlanTravels/TripKeys.php` distinct `route_key` values from legs, split into the pso-services request shape.
- `app/Tools/PlanTravels/PlanTravelsTool.php` the Tool: kinds, option fields, run pipeline, summary.
- `app/Tools/Contracts/ToolFailedWithResult.php` a failure that carries the partial `ToolResult`.
- `app/Support/PsoServices/PsoServicesRoutesClient.php`, `PsoServicesRoutesException.php`.
- `resources/views/filament/tool-runs/plan_travels.blade.php`.
- `tests/Fixtures/pso-tools/plan-travels/plan_travels.py` (stub), `plan_travels_template.html`.
- `tests/Feature/PlanTravels/PlanTravelsCommandTest.php`, `TripKeysTest.php`, `PsoServicesRoutesClientTest.php`, `PlanTravelsToolTest.php`, `PlanTravelsWizardTest.php`, `PlanTravelsRunPageTest.php`, `TravelAnalyzerPrefillTest.php`.

Modify:
- `app/Tools/LoadAudit/PsoToolsCheckout.php` (two paths, after line 54).
- `app/Tools/ScriptRun.php` (optional `progressLabel` argument to `execute()`, line 29; used in `$handleLine`, lines 36 to 48).
- `app/Jobs/RunToolJob.php` (catch `ToolFailedWithResult` before line 83).
- `app/Providers/AppServiceProvider.php` (register, after line 34).
- `app/Filament/Pages/Tools/ToolWizardPage.php` (an `afterInputsChanged()` hook called from `rememberChoice()` line 295 and `refreshInputs()` line 308).
- `app/Filament/Pages/Tools/PlanTravelsEntry.php` (fills the first date from the chosen plan export).
- `app/Http/Controllers/ToolRunPageController.php` (`downloadCsv`, per-tool sandbox, first date in file names) and `routes/web.php` (one route after line 28).
- `app/Filament/Pages/TravelAnalyzer.php` (`mount()` lines 51 to 56).
- `config/tools.php`, `config/psott.php`, `.env.example`, `README.md`.
- `tests/Feature/ToolWizardPageTest.php` lines 271 to 278 (uses Plan travels as the example of an unregistered tool; switch to Daily schedule review).

---

### Task 1: Config, checkout paths, stub script and PlanTravelsCommand

**Files:**
- Create: `app/Tools/PlanTravels/PlanTravelsCommand.php`, `tests/Fixtures/pso-tools/plan-travels/plan_travels.py`, `tests/Fixtures/pso-tools/plan-travels/plan_travels_template.html`, `tests/Feature/PlanTravels/PlanTravelsCommandTest.php`
- Modify: `app/Tools/LoadAudit/PsoToolsCheckout.php`, `config/tools.php`, `config/psott.php`, `.env.example`

**Interfaces (Produces):**
```php
// PsoToolsCheckout
public function planTravelsScript(): string    // <root>/plan-travels/plan_travels.py
public function planTravelsTemplate(): string  // <root>/plan-travels/plan_travels_template.html

// PlanTravelsCommand
public const OUT_JSON = 'legs.json'; OUT_CSV = 'plan_travels.csv'; OUT_HTML = 'plan_travels.html'; GOOGLE_JSON = 'google.json';
public static function build(PsoToolsCheckout $checkout, string $workDir, string $exportPath, string $firstDate, int $days, ?string $datasetName): array
public static function mergeGoogle(PsoToolsCheckout $checkout, string $workDir, float $reviewMinutes, float $reviewPct): array

// config
config('tools.plan_travels.timeout_seconds')  // 600
config('psott.routes.timeout')                // 300 s per chunk of 200
```

- [ ] **Step 0: Branch**

```bash
cd /Users/idawoodjee/Herd/pso-test-tools && git checkout main && git pull && git checkout -b feat/plan-travels
```

- [ ] **Step 1: Write the failing command test**

`tests/Feature/PlanTravels/PlanTravelsCommandTest.php`:
```php
<?php

use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\PlanTravels\PlanTravelsCommand;

beforeEach(function () {
    config(['tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3']);
    $this->checkout = PsoToolsCheckout::fromConfig();
});

it('builds the build argument list with the date, days, dataset name, out dir and template', function () {
    $argv = PlanTravelsCommand::build($this->checkout, '/w/', '/w/plan.xml', '2026-10-05', 5, 'NORTH');

    expect($argv)->toBe([
        $this->checkout->python(), $this->checkout->planTravelsScript(), 'build',
        '--export', '/w/plan.xml', '--date', '2026-10-05', '--days', '5', '--dataset-name', 'NORTH',
        '--out-dir', '/w', '--template', $this->checkout->planTravelsTemplate(),
    ]);
});

it('leaves out the dataset name when there is none', function (?string $name) {
    expect(PlanTravelsCommand::build($this->checkout, '/w', '/w/plan.xml', '2026-10-05', 1, $name))->not->toContain('--dataset-name');
})->with(['null' => [null], 'blank' => ['  ']]);

it('builds the merge-google argument list from the work dir with both thresholds', function () {
    $argv = PlanTravelsCommand::mergeGoogle($this->checkout, '/w', 20.0, 12.5);

    expect($argv)->toBe([
        $this->checkout->python(), $this->checkout->planTravelsScript(), 'merge-google',
        '--legs', '/w/legs.json', '--google', '/w/google.json', '--out-dir', '/w',
        '--template', $this->checkout->planTravelsTemplate(),
        '--review-minutes', '20', '--review-pct', '12.5',
    ]);
});

it('prints a zero percent threshold as 0', function () {
    expect(PlanTravelsCommand::mergeGoogle($this->checkout, '/w', 0.0, 0.0))->toContain('--review-minutes', '0', '--review-pct', '0');
});

it('names the missing script as a setup problem', function () {
    config(['tools.pso_tools_path' => sys_get_temp_dir()]);

    expect(fn () => PsoToolsCheckout::fromConfig()->planTravelsScript())
        ->toThrow(App\Tools\Contracts\ToolSetupException::class, 'plan-travels/plan_travels.py is missing');
});
```

- [ ] **Step 2: Run to see it fail**

Run: `php artisan test --compact tests/Feature/PlanTravels/PlanTravelsCommandTest.php`
Expected: FAIL, class `App\Tools\PlanTravels\PlanTravelsCommand` not found.

- [ ] **Step 3: Implement the checkout paths, the command, the stub and the config**

`app/Tools/LoadAudit/PsoToolsCheckout.php`, after `activityTraceTemplate()` (line 54):
```php
    public function planTravelsScript(): string
    {
        return $this->file('plan-travels/plan_travels.py');
    }

    public function planTravelsTemplate(): string
    {
        return $this->file('plan-travels/plan_travels_template.html');
    }
```

`app/Tools/PlanTravels/PlanTravelsCommand.php`:
```php
<?php

namespace App\Tools\PlanTravels;

use App\Tools\LoadAudit\PsoToolsCheckout;

/** The argument lists for plan_travels.py build and merge-google. Pure: no process, no filesystem, so every shape is unit tested. */
class PlanTravelsCommand
{
    public const OUT_JSON = 'legs.json';

    public const OUT_CSV = 'plan_travels.csv';

    public const OUT_HTML = 'plan_travels.html';

    public const GOOGLE_JSON = 'google.json';

    /** @return list<string> */
    public static function build(PsoToolsCheckout $checkout, string $workDir, string $exportPath, string $firstDate, int $days, ?string $datasetName): array
    {
        $argv = [$checkout->python(), $checkout->planTravelsScript(), 'build', '--export', $exportPath, '--date', $firstDate, '--days', (string) $days];

        // Shown in the page header; the script prints none when it is not given.
        if (filled(trim((string) $datasetName))) {
            array_push($argv, '--dataset-name', trim((string) $datasetName));
        }

        array_push($argv, '--out-dir', rtrim($workDir, '/'), '--template', $checkout->planTravelsTemplate());

        return $argv;
    }

    /** @return list<string> */
    public static function mergeGoogle(PsoToolsCheckout $checkout, string $workDir, float $reviewMinutes, float $reviewPct): array
    {
        $dir = rtrim($workDir, '/');

        return [
            $checkout->python(), $checkout->planTravelsScript(), 'merge-google',
            '--legs', $dir.'/'.self::OUT_JSON, '--google', $dir.'/'.self::GOOGLE_JSON, '--out-dir', $dir,
            '--template', $checkout->planTravelsTemplate(),
            '--review-minutes', self::number($reviewMinutes), '--review-pct', self::number($reviewPct),
        ];
    }

    /** 20.0 is "20", 12.5 is "12.5": the script parses either and the argv stays readable in the logs. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
```

`tests/Fixtures/pso-tools/plan-travels/plan_travels_template.html`:
```html
<!doctype html><html><head><title>__TITLE__</title></head><body><script>const DATA = __DATA__;</script></body></html>
```

`tests/Fixtures/pso-tools/plan-travels/plan_travels.py`:
```python
"""Stand-in for pso-tools/plan-travels/plan_travels.py in tests. Same commands, arguments and output files; reads no XML.

FAKE_PLAN_MODE: ok (default) | fail (exit 1 with a Stopped during line) | silent (exit 0, writes nothing).
FAKE_PLAN_LEGS: how many generated legs follow the three fixed ones (default 3).
FAKE_PLAN_ARGS_FILE: append one JSON line per call (argv, cwd, PSO_PROGRESS, and for merge-google the google.json it read).

Every leg carries route_key ('%.6f' on each coordinate, null when one is missing), as the real script does.
Fixed legs: A1 and A2 share one trip whose origin latitude has 17 digits; A3 has no start coordinates.
Generated leg i: resource R(3 + i % 5), origin (50 + i/1000, -106.0), destination (51 + i/1000, -106.1).
Progress: only PROGRESS {"stage":"reading","percent":N}, 0 first and 100 last, for both commands.
merge-google matches google.json on route_key verbatim. Flags: Review when Google is 1200 s or more over the
600 s PSO time, OK otherwise, Missing Google Time for 'No route returned', Skipped for no route_key or no result.
"""
import json, os, sys

def arg(name, default=None):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else default

command = sys.argv[1] if len(sys.argv) > 1 else ''
mode = os.environ.get('FAKE_PLAN_MODE', 'ok')
out = arg('--out-dir', '.')

def progress(stage, percent):
    if os.environ.get('PSO_PROGRESS') == 'lines':
        sys.stderr.write('PROGRESS %s\n' % json.dumps({'stage': stage, 'percent': percent}, separators=(',', ':')))

def record(extra):
    path = os.environ.get('FAKE_PLAN_ARGS_FILE')
    if path:
        with open(path, 'a') as fh:
            fh.write(json.dumps(dict({'argv': sys.argv[1:], 'cwd': os.getcwd(), 'progress_env': os.environ.get('PSO_PROGRESS')}, **extra)) + '\n')

def leg(resource, olat, olng, dlat, dlng, dest):
    coords = (olat, olng, dlat, dlng)
    route_key = None if None in coords else '%.6f,%.6f|%.6f,%.6f' % coords
    return {'resource_id': resource, 'direction': 'Activity -> Activity', 'activity_id': dest,
            'start_lat': olat, 'start_lng': olng, 'end_lat': dlat, 'end_lng': dlng,
            'travel_sec': 600, 'plan_id': 'P1', 'route_key': route_key, 'flag': None}

def write(legs, meta):
    with open(os.path.join(out, 'legs.json'), 'w') as fh:
        json.dump({'meta': meta, 'legs': legs}, fh)
    with open(os.path.join(out, 'plan_travels.csv'), 'w') as fh:
        fh.write('Resource ID,Flag\n' + ''.join('%s,%s\n' % (l['resource_id'], l.get('flag') or '') for l in legs))
    with open(os.path.join(out, 'plan_travels.html'), 'w') as fh:
        fh.write('<!doctype html><html><body>Plan travels page %s</body></html>' % ('with Google' if meta['google'] else 'without Google'))
    for warning in meta['warnings']:
        sys.stderr.write('warning: %s\n' % warning)
    print('summary: %d legs' % len(legs))

if command == 'build':
    record({})
    progress('reading', 0)
    progress('reading', 50)
    if mode == 'fail':
        sys.stderr.write('Stopped during: reading the export: the root element is Foo, not dsScheduleData\n')
        sys.exit(1)
    if mode == 'silent':
        sys.exit(0)
    legs = [leg('R1', 52.123456789012345, -106.5, 52.2, -106.6, 'A1'),
            leg('R1', 52.123456789012345, -106.5, 52.2, -106.6, 'A2'),
            leg('R2', None, None, 52.3, -106.7, 'A3')]
    for i in range(int(os.environ.get('FAKE_PLAN_LEGS', '3'))):
        legs.append(leg('R%d' % (3 + i % 5), 50 + i / 1000.0, -106.0, 51 + i / 1000.0, -106.1, 'G%d' % i))
    progress('reading', 100)
    write(legs, {'export_name': os.path.basename(arg('--export')), 'dataset_name': arg('--dataset-name'), 'date_from': arg('--date'),
                 'days': int(arg('--days', '1')), 'google': False, 'warnings': ['2 fragments could not be merged']})
elif command == 'merge-google':
    with open(arg('--google')) as fh:
        google = json.load(fh)
    with open(arg('--legs')) as fh:
        data = json.load(fh)
    record({'google': google})
    progress('reading', 0)
    for l in data['legs']:
        if l['route_key'] is None:
            l['flag'] = 'Skipped'
            continue
        g = google.get(l['route_key'])
        if g is None:
            l['flag'] = 'Skipped'
        elif 'error' in g:
            l['flag'] = 'Missing Google Time' if g['error'] == 'No route returned' else 'Skipped'
        else:
            l['google_sec'] = g['durationSec']
            l['google_source'] = g['source']
            l['flag'] = 'Review' if g['durationSec'] - 600 >= 1200 else 'OK'
    data['meta']['google'] = True
    data['meta']['review_minutes'] = float(arg('--review-minutes', '20'))
    data['meta']['review_pct'] = float(arg('--review-pct', '0'))
    progress('reading', 100)
    write(data['legs'], data['meta'])
else:
    sys.stderr.write('Stopped during: starting: unknown command %s\n' % command)
    sys.exit(1)
```

`config/tools.php`, after the `activity_trace` block (line 19):
```php
    'plan_travels' => [
        // One script run (build, or merge-google); the Google step between them has its own timeout per chunk (psott.routes.timeout).
        'timeout_seconds' => (int) env('TOOLS_PLAN_TRAVELS_TIMEOUT_SECONDS', 600),
    ],
```

`config/psott.php`, after the `session` block (line 16):
```php
    'routes' => [
        // One chunk of up to 200 trips to pso-services /routes/compute; cache misses call Google one by one with a 150 ms gap.
        'timeout' => (int) env('PSO_SERVICES_ROUTES_TIMEOUT', 300),
    ],
```

`.env.example`, after `PSO_SESSION_TIMEOUT=20` (line 86):
```
# Seconds allowed for one chunk of 200 trips sent to pso-services for Google Routes (Plan travels).
PSO_SERVICES_ROUTES_TIMEOUT=300
```
and after `TOOLS_PYTHON=python3` (line 102):
```
TOOLS_PLAN_TRAVELS_TIMEOUT_SECONDS=600
```

- [ ] **Step 4: Run to see it pass**

Run: `php artisan test --compact tests/Feature/PlanTravels/PlanTravelsCommandTest.php`
Expected: 6 passed.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty && git add -A && git commit -m "Plan travels: command builder, checkout paths, config and stub script"
```

---

### Task 2: Trip keys and the pso-services routes client

**Files:**
- Create: `app/Tools/PlanTravels/TripKeys.php`, `app/Support/PsoServices/PsoServicesRoutesClient.php`, `app/Support/PsoServices/PsoServicesRoutesException.php`, `tests/Feature/PlanTravels/TripKeysTest.php`, `tests/Feature/PlanTravels/PsoServicesRoutesClientTest.php`

**Interfaces (Produces):**
```php
// TripKeys (never formats a coordinate: route_key comes from legs.json and is copied verbatim)
/** @return array{origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}|null null unless "<lat>,<lng>|<lat>,<lng>" with numbers in range */
public static function split(string $routeKey): ?array
/** @return array<string, array{origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}> keyed by route_key, first seen order */
public static function trips(array $legs): array

// PsoServicesRoutesClient
/**
 * @param list<array{id: string, origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}> $legs  1 to 200
 * @return array{results: list<array<string, mixed>>, counts: array<string, int>}
 * @throws PsoServicesRoutesException  unreachable, non-2xx, or no results array
 */
public function compute(array $legs, string $travelMode, string $routingPreference): array

// PsoServicesRoutesException extends RuntimeException { public int $statusCode }  // 0 when unreachable
```

- [ ] **Step 1: Write the failing tests**

`tests/Feature/PlanTravels/TripKeysTest.php`:
```php
<?php

use App\Tools\PlanTravels\TripKeys;

function tripLeg(mixed $routeKey): array
{
    return ['resource_id' => 'R1', 'route_key' => $routeKey];
}

it('copies route keys verbatim and splits them for the request', function () {
    $trips = TripKeys::trips([tripLeg('52.123457,-106.500000|52.200000,-106.600000')]);

    expect(array_keys($trips))->toBe(['52.123457,-106.500000|52.200000,-106.600000'])
        ->and($trips['52.123457,-106.500000|52.200000,-106.600000'])->toBe(['origin' => ['lat' => 52.123457, 'lng' => -106.5], 'destination' => ['lat' => 52.2, 'lng' => -106.6]])
        ->and(TripKeys::split('-0.000000,180.000000|1.000000,-180.000000'))->toBe(['origin' => ['lat' => -0.0, 'lng' => 180.0], 'destination' => ['lat' => 1.0, 'lng' => -180.0]]);
});

it('keeps one trip per distinct route key in first seen order', function () {
    $trips = TripKeys::trips([
        tripLeg('52.123457,-106.500000|52.200000,-106.600000'),
        tripLeg('50.001000,-106.000000|51.001000,-106.100000'),
        tripLeg('52.123457,-106.500000|52.200000,-106.600000'),
    ]);

    expect(array_keys($trips))->toBe(['52.123457,-106.500000|52.200000,-106.600000', '50.001000,-106.000000|51.001000,-106.100000']);
});

it('leaves out legs whose route key is null, not text, malformed or out of range', function () {
    $trips = TripKeys::trips([
        tripLeg(null),
        tripLeg(52.1),
        tripLeg(''),
        tripLeg('52.100000,-106.500000'),
        tripLeg('52.100000,-106.500000|52.200000'),
        tripLeg('52.1,-106.5|52.2,-106.6|1,1'),
        tripLeg('north,-106.500000|52.200000,-106.600000'),
        tripLeg('91.000000,-106.500000|52.200000,-106.600000'),
        tripLeg('52.100000,-181.000000|52.200000,-106.600000'),
        ['resource_id' => 'R9'],
        'not a leg',
        tripLeg('52.100000,-106.500000|52.200000,-106.600000'),
    ]);

    expect(array_keys($trips))->toBe(['52.100000,-106.500000|52.200000,-106.600000']);
});
```

`tests/Feature/PlanTravels/PsoServicesRoutesClientTest.php`:
```php
<?php

use App\Support\PsoServices\PsoServicesRoutesClient;
use App\Support\PsoServices\PsoServicesRoutesException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['psott.pso-services-api' => 'pso-services.test', 'psott.pso-services-api-version' => 'v2']);
    $this->legs = [['id' => 'a', 'origin' => ['lat' => 52.1, 'lng' => -106.6], 'destination' => ['lat' => 52.2, 'lng' => -106.7]]];
});

it('posts the legs with mode and preference as JSON and returns results and counts', function () {
    Http::fake(['https://pso-services.test/api/v2/routes/compute' => Http::response([
        'results' => [['id' => 'a', 'durationSec' => 4374, 'distanceKm' => 117.04, 'source' => 'cache']],
        'counts' => ['cache' => 1, 'google' => 0, 'error' => 0],
    ])]);

    $response = app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE');

    expect($response)->toBe([
        'results' => [['id' => 'a', 'durationSec' => 4374, 'distanceKm' => 117.04, 'source' => 'cache']],
        'counts' => ['cache' => 1, 'google' => 0, 'error' => 0],
    ]);
    Http::assertSent(static fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://pso-services.test/api/v2/routes/compute'
        && $request->hasHeader('Accept', 'application/json')
        && $request->data() === ['travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE', 'legs' => test()->legs]
        && ! $request->hasHeader('X-Goog-Api-Key'));
});

it('throws the pso-services message and status on a 503', function () {
    Http::fake(['*' => Http::response(['message' => 'Google Routes is not configured on pso-services.'], 503)]);

    try {
        app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE');
        $this->fail('expected a PsoServicesRoutesException');
    } catch (PsoServicesRoutesException $e) {
        expect($e->getMessage())->toBe('Google Routes is not configured on pso-services.')
            ->and($e->statusCode)->toBe(503);
    }
});

it('names the first validation error on a 422', function () {
    Http::fake(['*' => Http::response(['message' => 'The legs field must not have more than 200 items.', 'errors' => ['legs' => ['The legs field must not have more than 200 items.']]], 422)]);

    expect(fn () => app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE'))
        ->toThrow(PsoServicesRoutesException::class, 'The legs field must not have more than 200 items.');
});

it('says the API returned the status when a 500 has no body', function () {
    Http::fake(['*' => Http::response('', 500)]);

    expect(fn () => app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE'))
        ->toThrow(PsoServicesRoutesException::class, 'The PSO services API returned 500.');
});

it('says the API could not be reached on a connection failure', function () {
    Http::fake(static fn () => throw new ConnectionException('cURL error 28'));

    expect(fn () => app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE'))
        ->toThrow(PsoServicesRoutesException::class, 'The PSO services API could not be reached (timed out or unreachable).');
});

it('refuses a 200 without a results array', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    expect(fn () => app(PsoServicesRoutesClient::class)->compute($this->legs, 'DRIVE', 'TRAFFIC_UNAWARE'))
        ->toThrow(PsoServicesRoutesException::class, 'The PSO services API answered without route results.');
});
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/PlanTravels/TripKeysTest.php tests/Feature/PlanTravels/PsoServicesRoutesClientTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

`app/Tools/PlanTravels/TripKeys.php`:
```php
<?php

namespace App\Tools\PlanTravels;

/**
 * The distinct trips in a legs.json, by each leg's route_key ("<oLat>,<oLng>|<dLat>,<dLng>", six decimals,
 * made by the script). The key is copied verbatim into google.json, because merge-google matches on it;
 * this class only splits it into the pso-services request and never formats a coordinate itself.
 */
final class TripKeys
{
    private const LIMITS = [90.0, 180.0, 90.0, 180.0];

    /** @return array{origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}|null */
    public static function split(string $routeKey): ?array
    {
        $ends = explode('|', $routeKey);
        if (count($ends) !== 2) {
            return null;
        }
        $parts = [...explode(',', $ends[0]), ...explode(',', $ends[1])];
        if (count($parts) !== 4 || substr_count($ends[0], ',') !== 1) {
            return null;
        }

        $values = [];
        foreach ($parts as $i => $part) {
            if (! is_numeric($part) || abs((float) $part) > self::LIMITS[$i]) {
                return null;
            }
            $values[] = (float) $part;
        }
        [$oLat, $oLng, $dLat, $dLng] = $values;

        return ['origin' => ['lat' => $oLat, 'lng' => $oLng], 'destination' => ['lat' => $dLat, 'lng' => $dLng]];
    }

    /**
     * @param  array<int, mixed>  $legs
     * @return array<string, array{origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}>
     */
    public static function trips(array $legs): array
    {
        $trips = [];
        foreach ($legs as $leg) {
            $key = is_array($leg) ? ($leg['route_key'] ?? null) : null;
            if (! is_string($key) || isset($trips[$key])) {
                continue;
            }
            $trip = self::split($key);
            if ($trip !== null) {
                $trips[$key] = $trip;
            }
        }

        return $trips;
    }
}
```

`app/Support/PsoServices/PsoServicesRoutesException.php`:
```php
<?php

namespace App\Support\PsoServices;

use RuntimeException;
use Throwable;

class PsoServicesRoutesException extends RuntimeException
{
    /** @param int $statusCode HTTP status from pso-services, 0 when it could not be reached */
    public function __construct(string $message, public int $statusCode = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
```

`app/Support/PsoServices/PsoServicesRoutesClient.php` (base URL, Accept header and error handling copied from `PsoServicesSessionClient` lines 56 to 115):
```php
<?php

namespace App\Support\PsoServices;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Routes through pso-services (POST /routes/compute, spec 5.3): pso-services holds the key and the
 * shared cache, so this app never sees a Google key. One call per chunk of up to 200 trips.
 */
class PsoServicesRoutesClient
{
    /**
     * @param  list<array{id: string, origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}>  $legs
     * @return array{results: list<array<string, mixed>>, counts: array<string, int>}
     *
     * @throws PsoServicesRoutesException
     */
    public function compute(array $legs, string $travelMode, string $routingPreference): array
    {
        $url = $this->url();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('psott.routes.timeout', 300))
                ->connectTimeout(10)
                ->post($url, ['travelMode' => $travelMode, 'routingPreference' => $routingPreference, 'legs' => $legs]);
        } catch (ConnectionException $exception) {
            Log::warning('PSO services routes: the API could not be reached', ['url' => $url, 'legs' => count($legs), 'message' => $exception->getMessage()]);

            throw new PsoServicesRoutesException('The PSO services API could not be reached (timed out or unreachable).', 0, $exception);
        }

        if (! $response->successful()) {
            throw new PsoServicesRoutesException($this->errorMessage($response), $response->status());
        }

        $results = $response->json('results');
        if (! is_array($results)) {
            throw new PsoServicesRoutesException('The PSO services API answered without route results.', $response->status());
        }

        $counts = $response->json('counts');

        return [
            'results' => array_values(array_filter($results, 'is_array')),
            'counts' => is_array($counts) ? array_map('intval', $counts) : [],
        ];
    }

    private function url(): string
    {
        $version = config('psott.pso-services-api-version');

        return 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').'routes/compute';
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();
        $candidates = [data_get($body, 'error'), data_get($body, 'message')];
        $validationErrors = data_get($body, 'errors');
        if (is_array($validationErrors)) {
            $candidates[] = collect($validationErrors)->flatten()->first();
        }

        $parts = array_values(array_unique(array_filter($candidates, static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== '')));

        return $parts === [] ? "The PSO services API returned {$response->status()}." : implode(' ', $parts);
    }
}
```

- [ ] **Step 4: Run to see them pass**

Run: `php artisan test --compact tests/Feature/PlanTravels/TripKeysTest.php tests/Feature/PlanTravels/PsoServicesRoutesClientTest.php`
Expected: 9 passed. (The 422 case passes because `array_unique` folds the message and the identical first error into one.)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty && git add -A && git commit -m "Plan travels: trip keys and the pso-services routes client"
```

---

### Task 3: A failed run can keep what the tool made

**Files:**
- Create: `app/Tools/Contracts/ToolFailedWithResult.php`
- Modify: `app/Jobs/RunToolJob.php` (new catch before line 83), `tests/Feature/RunToolJobTest.php` (one test appended)

**Interfaces (Produces):**
```php
// ToolFailedWithResult extends RuntimeException
public function __construct(string $message, public ToolResult $partial)
// RunToolJob stores $partial->result, ->warnings, ->summary (with the time zone meta), then marks the run Failed with $message.
```

- [ ] **Step 1: Write the failing test** (append to `tests/Feature/RunToolJobTest.php`)

```php
it('keeps the partial result of a run that failed after building', function () {
    FakeTool::$throw = new App\Tools\Contracts\ToolFailedWithResult(
        'The Google Routes check did not finish: Google Routes is not configured on pso-services.',
        new App\Tools\Contracts\ToolResult(['html' => '<p>legs</p>', 'csv' => "a\n"], ['Legs shown without Google'], ['leg_count' => 3]),
    );
    $run = runFor($this->dataset, $this->load);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));
    FakeTool::$throw = null;

    $run->refresh();
    expect($run->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toBe('The Google Routes check did not finish: Google Routes is not configured on pso-services.')
        ->and($run->result)->toBe(['html' => '<p>legs</p>', 'csv' => "a\n"])
        ->and($run->warnings)->toBe(['Legs shown without Google'])
        ->and($run->summary['leg_count'])->toBe(3)
        ->and($run->progress)->toBeNull()
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});
```

- [ ] **Step 2: Run to see it fail**

Run: `php artisan test --compact tests/Feature/RunToolJobTest.php`
Expected: FAIL, class `App\Tools\Contracts\ToolFailedWithResult` not found.

- [ ] **Step 3: Implement**

`app/Tools/Contracts/ToolFailedWithResult.php`:
```php
<?php

namespace App\Tools\Contracts;

use RuntimeException;

/**
 * A run that failed part of the way with something worth showing: RunToolJob stores the partial result,
 * warnings and summary, then marks the run failed with this message. Plan travels uses it when Google
 * fails after the legs were built, so the run still shows the legs and says why Google is missing.
 */
class ToolFailedWithResult extends RuntimeException
{
    public function __construct(string $message, public ToolResult $partial)
    {
        parent::__construct($message);
    }
}
```

`app/Jobs/RunToolJob.php`: add `use App\Tools\Contracts\ToolFailedWithResult;` and, between the end of the `try` block (line 82) and `catch (Throwable $exception)` (line 83):
```php
        } catch (ToolFailedWithResult $exception) {
            $summary = $exception->partial->summary;
            if ($meta !== []) {
                $summary['meta'] = [...(is_array($summary['meta'] ?? null) ? $summary['meta'] : []), ...$meta];
            }
            $run->forceFill([
                'result' => $exception->partial->result ?: null,
                'warnings' => $exception->partial->warnings,
                'summary' => $summary ?: null,
            ])->save();

            $this->markFailed($run, $exception->getMessage());
```

- [ ] **Step 4: Run to see it pass**

Run: `php artisan test --compact tests/Feature/RunToolJobTest.php`
Expected: all pass (the existing `records a tool exception as a failed run` test still passes: a plain RuntimeException still goes to the Throwable branch).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty && git add -A && git commit -m "Tool runs: a failed run keeps the partial result a tool hands back"
```

---

### Task 4: PlanTravelsTool, registration and the run pipeline

**Files:**
- Create: `app/Tools/PlanTravels/PlanTravelsTool.php`, `tests/Feature/PlanTravels/PlanTravelsToolTest.php`
- Modify: `app/Providers/AppServiceProvider.php` (line 34), `app/Tools/ScriptRun.php` (lines 29 and 36 to 48), `tests/Feature/ToolWizardPageTest.php` (lines 9 and 271 to 278)

**Interfaces (Consumes):** `PlanTravelsCommand` (Task 1), `TripKeys`, `PsoServicesRoutesClient` (Task 2), `ToolFailedWithResult` (Task 3), `ScriptRun::execute` (`app/Tools/ScriptRun.php` line 29), `ToolContext::reportProgress` (`app/Tools/ToolContext.php` line 30), `ExportFile::exportDate()` (`app/Models/ExportFile.php` line 131).

**Interfaces (Produces):**
```php
class PlanTravelsTool implements Tool
{
    public const CHUNK = 200;
    public const TRAVEL_MODES = ['DRIVE' => 'Driving', 'TWO_WHEELER' => 'Two-wheeler', 'BICYCLE' => 'Bicycle', 'WALK' => 'Walking', 'TRANSIT' => 'Public transport'];
    public const ROUTING_PREFERENCES = ['TRAFFIC_UNAWARE' => 'Ignore traffic (as PSO does)', 'TRAFFIC_AWARE' => 'Live traffic', 'TRAFFIC_AWARE_OPTIMAL' => 'Live traffic, best route'];
    public function __construct(private PsoServicesRoutesClient $routes) {}
    // options: first_date (Y-m-d), days (1..31), google (bool), travel_mode, routing_preference, review_minutes, review_pct
}
// result:  ['html' => string, 'csv' => string, 'data' => array (legs.json)]
// summary: ['leg_count','resource_count','review_count','missing_count' => int, 'first_date','last_date' => 'Y-m-d', 'days' => int,
//           'google' => ['cache','google','error' => int]|null, 'line' => string]
```

- [ ] **Step 1: Write the failing tool tests**

`tests/Feature/PlanTravels/PlanTravelsToolTest.php`:
```php
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
        ->and($result->result['data']['legs'])->toHaveCount(6)
        ->and($result->warnings)->toBe(['2 fragments could not be merged'])
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
            'line' => '6 legs for 5 resources, 2026-10-05; Google: 2 from the cache, 1 fetched, 1 without a route',
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
        ->and($labels)->toContain('Building legs', 'Building legs: 0%', 'Checking trips with Google, 1 of 3', 'Checking trips with Google, 2 of 3', 'Checking trips with Google, 3 of 3', 'Merging Google results', 'Merging Google results: 0%')
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
            ->and($e->partial->result['data']['legs'])->toHaveCount(6)
            ->and($e->partial->warnings)->toBe(['2 fragments could not be merged', 'Google Routes was not checked, so the legs have no Google columns: Google Routes is not configured on pso-services.'])
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
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/PlanTravels/PlanTravelsToolTest.php`
Expected: FAIL, `No implementation registered for Plan travels.` / class not found.

- [ ] **Step 3: Implement the tool, register it and add the progress labels**

`app/Tools/PlanTravels/PlanTravelsTool.php`:
```php
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
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs pso-tools/plan-travels/plan_travels.py on a plan export (the legs), optionally checks each distinct
 * trip with Google Routes through pso-services, merges the answers back in with the script, and keeps the
 * page, the CSV and legs.json. Google failing after the build fails the run but keeps the legs.
 */
class PlanTravelsTool implements Tool
{
    public const CHUNK = 200;

    public const TRAVEL_MODES = ['DRIVE' => 'Driving', 'TWO_WHEELER' => 'Two-wheeler', 'BICYCLE' => 'Bicycle', 'WALK' => 'Walking', 'TRANSIT' => 'Public transport'];

    public const ROUTING_PREFERENCES = ['TRAFFIC_UNAWARE' => 'Ignore traffic (as PSO does)', 'TRAFFIC_AWARE' => 'Live traffic', 'TRAFFIC_AWARE_OPTIMAL' => 'Live traffic, best route'];

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

        $context->reportProgress('Building legs', 0, null, 'build');
        $datasetName = $context->settings?->displayName ?: ($context->scope instanceof Dataset ? $context->scope->name : null);
        $argv = PlanTravelsCommand::build($checkout, $workDir, $inputPaths['plan'], $firstDate, $days, $datasetName);
        ScriptRun::execute($context, $workDir, $argv, $timeout, 'Plan travels', progressLabel: 'Building legs');
        $built = $this->readOutputs($workDir);

        if (! (bool) ($options['google'] ?? false)) {
            return $this->result($built, $firstDate, $days, null);
        }

        $mode = array_key_exists((string) ($options['travel_mode'] ?? ''), self::TRAVEL_MODES) ? (string) $options['travel_mode'] : 'DRIVE';
        $preference = array_key_exists((string) ($options['routing_preference'] ?? ''), self::ROUTING_PREFERENCES) ? (string) $options['routing_preference'] : 'TRAFFIC_UNAWARE';

        try {
            [$google, $counts] = $this->checkWithGoogle($context, TripKeys::trips($built['data']['legs'] ?? []), $mode, $preference);
        } catch (PsoServicesRoutesException $e) {
            $partial = $this->result($built, $firstDate, $days, null);

            throw new ToolFailedWithResult(
                'The Google Routes check did not finish: '.$e->getMessage(),
                new ToolResult($partial->result, [...$partial->warnings, 'Google Routes was not checked, so the legs have no Google columns: '.$e->getMessage()], $partial->summary),
            );
        }

        file_put_contents($workDir.'/'.PlanTravelsCommand::GOOGLE_JSON, json_encode((object) $google, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        $context->reportProgress('Merging Google results', 95, null, 'merge');
        $argv = PlanTravelsCommand::mergeGoogle($checkout, $workDir, (float) ($options['review_minutes'] ?? 20), (float) ($options['review_pct'] ?? 0));
        ScriptRun::execute($context, $workDir, $argv, $timeout, 'Plan travels', progressLabel: 'Merging Google results');

        return $this->result($this->readOutputs($workDir), $firstDate, $days, $counts);
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
     * @param  array<string, array{origin: array{lat: float, lng: float}, destination: array{lat: float, lng: float}}>  $trips
     * @return array{0: array<string, array<string, mixed>>, 1: array{cache: int, google: int, error: int}}
     */
    private function checkWithGoogle(ToolContext $context, array $trips, string $mode, string $preference): array
    {
        $chunks = array_chunk($trips, self::CHUNK, true);
        $total = count($chunks);
        $google = [];
        $counts = ['cache' => 0, 'google' => 0, 'error' => 0];

        foreach ($chunks as $i => $chunk) {
            $number = $i + 1;
            $context->reportProgress("Checking trips with Google, {$number} of {$total}", intdiv($i * 100, $total), null, "google-{$number}");

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
                $google[$id] = isset($answer['error'])
                    ? ['error' => (string) $answer['error']]
                    : ['durationSec' => (int) ($answer['durationSec'] ?? 0), 'distanceKm' => (float) ($answer['distanceKm'] ?? 0), 'source' => (string) ($answer['source'] ?? '')];
            }
            foreach (array_keys($counts) as $source) {
                $counts[$source] += (int) ($response['counts'][$source] ?? 0);
            }
        }

        return [$google, $counts];
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
     */
    private function result(array $outputs, string $firstDate, int $days, ?array $google): ToolResult
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

        $warnings = array_values(array_filter($outputs['data']['meta']['warnings'] ?? [], static fn (mixed $w): bool => is_string($w) && trim($w) !== ''));

        return new ToolResult(result: $outputs, warnings: $warnings, summary: $summary);
    }

    /** "6 legs for 5 resources, 2026-10-05 to 2026-10-09; Google: 2 from the cache, 1 fetched, 1 without a route" */
    private static function line(array $summary): string
    {
        $line = number_format($summary['leg_count']).' '.Str::plural('leg', $summary['leg_count'])
            .' for '.number_format($summary['resource_count']).' '.Str::plural('resource', $summary['resource_count']).', '
            .($summary['first_date'] === $summary['last_date'] ? $summary['first_date'] : "{$summary['first_date']} to {$summary['last_date']}");

        if ($summary['google'] !== null) {
            $g = $summary['google'];
            $line .= '; Google: '.number_format($g['cache']).' from the cache, '.number_format($g['google']).' fetched, '.number_format($g['error']).' without a route';
        }

        return $line;
    }
}
```

`app/Providers/AppServiceProvider.php`: add `use App\Tools\PlanTravels\PlanTravelsTool;` and after line 34:
```php
            $registry->register(ToolId::PlanTravels, PlanTravelsTool::class);
```

`app/Tools/ScriptRun.php`: plan_travels.py reports only the stage `reading` for both commands, and `ProgressLine` labels that stage "Reading the audit messages" (the trace's word), so `execute()` takes the label from the caller. Change the signature (line 29) to:
```php
    public static function execute(ToolContext $context, string $workDir, array $argv, int $timeout, string $noun, ?int $reasonExitCode = null, ?string $progressLabel = null): self
```
add `@param ?string $progressLabel names what the script is doing in place of ProgressLine's stage label ("Building legs: 40%")` to the docblock, and in `$handleLine` (lines 36 to 48) capture it and relabel a parsed line before it is used:
```php
        $handleLine = static function (string $line) use (&$lastLabel, &$stderr, $context, $progressLabel): void {
            if ($line === '') {
                return;
            }
            $progress = ProgressLine::parse($line);
            if ($progress !== null && $progressLabel !== null) {
                $progress['label'] = $progress['percent'] === null ? $progressLabel : "{$progressLabel}: {$progress['percent']}%";
            }
            if ($progress !== null) {
                $lastLabel = $progress['label'];
                $context->reportProgress($progress['label'], $progress['percent'], $progress['seconds_left'], $progress['stage']);
            } elseif (! str_starts_with($line, 'PROGRESS ')) {
                $stderr[] = $line;
                $stderr = array_slice($stderr, -200);
            }
        };
```
Every existing caller passes no label and behaves as before. A failure with no `Stopped during:` line now reads "Plan travels stopped while building legs: ..." (the stage is the label before the colon, line 85).

`tests/Feature/ToolWizardPageTest.php`: Plan travels is now registered, so the unregistered-tool test (lines 271 to 278) uses the Daily schedule review instead. Replace the `PlanTravelsEntry` import (line 9) with `use App\Filament\Pages\Tools\ScheduleReviewEntry;` and the test body with:
```php
it('refuses an unregistered tool URL instead of crashing, and opens a registered one', function () {
    expect(app(ToolRegistry::class)->has(ToolId::ScheduleReview))->toBeFalse();
    // A user the panel admits, so the 403 is the page's own refusal.
    $this->actingAs(User::factory()->create(['email' => 'consultant@mac.com']));

    $this->get(ScheduleReviewEntry::getUrl())->assertForbidden();
    $this->get(LoadAuditEntry::getUrl())->assertOk();
});
```

- [ ] **Step 4: Run to see them pass**

Run: `php artisan test --compact tests/Feature/PlanTravels tests/Feature/ToolWizardPageTest.php`
Expected: all pass. Then the full suite: `php artisan test --compact` (only the known `ExampleTest` failure; if a catalogue or navigation test counted the registered tools, add Plan travels to its expectation).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty && git add -A && git commit -m "Plan travels: tool, options, Google check in chunks of 200 and registration"
```

---

### Task 5: The first date defaults to the plan export's date in the wizard

**Files:**
- Create: `tests/Feature/PlanTravels/PlanTravelsWizardTest.php`
- Modify: `app/Filament/Pages/Tools/ToolWizardPage.php` (`rememberChoice()` line 295, `refreshInputs()` line 308, new hook), `app/Filament/Pages/Tools/PlanTravelsEntry.php`

**Interfaces (Produces):**
```php
// ToolWizardPage
protected function afterInputsChanged(): void {}   // called at the end of rememberChoice() and refreshInputs()
// PlanTravelsEntry
public ?string $suggestedFirstDate = null;          // the date it last filled in, so a date the user typed is never overwritten
```

Why a hook: `form->fill()` in `mount()` (line 96) applies option defaults before any file is chosen, so a static `->default()` cannot know the export. The file selects are filled by `refreshInputs()` and changed through `rememberChoice()`, so those two are where the date can follow the file.

- [ ] **Step 1: Write the failing wizard tests**

`tests/Feature/PlanTravels/PlanTravelsWizardTest.php`:
```php
<?php

use App\Enums\ExportKind;
use App\Filament\Pages\Tools\PlanTravelsEntry;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina']]);
    $this->actingAs($this->user);
});

it('fills the first date from the chosen plan export and starts with one day and Google off', function () {
    $plan = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-05']]);

    Livewire::test(PlanTravelsEntry::class)
        ->assertFormSet(['files.plan' => $plan->id, 'options.first_date' => '2026-10-05', 'options.days' => 1, 'options.google' => false]);
});

it('follows a different plan export, but never replaces a date the user typed', function () {
    $older = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-04'], 'created_at' => now()->subHour()]);
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-05']]);

    $page = Livewire::test(PlanTravelsEntry::class)->assertFormSet(['options.first_date' => '2026-10-05']);
    $page->set('data.files.plan', $older->id)->call('rememberChoice', 'plan', $older->id)
        ->assertFormSet(['options.first_date' => '2026-10-04']);

    $page->set('data.options.first_date', '2026-10-01')->call('rememberChoice', 'plan', $older->id)
        ->assertFormSet(['options.first_date' => '2026-10-01']);
});

it('leaves the date blank when the export does not say its day', function () {
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => []]);

    Livewire::test(PlanTravelsEntry::class)->assertFormSet(['options.first_date' => null]);
});

it('shows the Google fields only when the toggle is on, with their defaults', function () {
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-05']]);

    Livewire::test(PlanTravelsEntry::class)
        ->assertFormFieldHidden('options.travel_mode')
        ->assertFormFieldHidden('options.review_pct')
        ->fillForm(['options.google' => true])
        ->assertFormFieldVisible('options.travel_mode')
        ->assertFormFieldVisible('options.routing_preference')
        ->assertFormFieldVisible('options.review_minutes')
        ->assertFormFieldVisible('options.review_pct')
        ->assertFormSet(['options.travel_mode' => 'DRIVE', 'options.routing_preference' => 'TRAFFIC_UNAWARE', 'options.review_minutes' => 20, 'options.review_pct' => 0])
        ->assertSee('Costs one Google call for each trip that is not already in the shared cache on pso-services.');
});
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/PlanTravels/PlanTravelsWizardTest.php`
Expected: FAIL on `options.first_date` (null instead of `2026-10-05`); the visibility test already passes.

- [ ] **Step 3: Implement the hook and the entry page**

`app/Filament/Pages/Tools/ToolWizardPage.php`: at the end of `rememberChoice()` (after line 299) and at the end of `refreshInputs()` (after the defaults loop, line 331) add:
```php
        $this->afterInputsChanged();
```
and add the method after `rememberChoice()`:
```php
    /** A tool page can derive an option from the chosen files here (Plan travels fills its first date). */
    protected function afterInputsChanged(): void {}
```

`app/Filament/Pages/Tools/PlanTravelsEntry.php`:
```php
<?php

namespace App\Filament\Pages\Tools;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\ExportFile;
use Livewire\Attributes\Locked;

class PlanTravelsEntry extends ToolWizardPage
{
    /** The first date this page last filled in from a plan export; a different value is the user's and is kept. */
    #[Locked]
    public ?string $suggestedFirstDate = null;

    public static function tool(): ToolId
    {
        return ToolId::PlanTravels;
    }

    /** The first date follows the chosen plan export (its Input_Reference day in the dataset zone) until the user types one. */
    protected function afterInputsChanged(): void
    {
        $fileId = $this->data['files'][ExportKind::Plan->value] ?? null;
        $date = blank($fileId) ? null : ExportFile::query()->find($fileId)?->exportDate();
        $current = $this->data['options']['first_date'] ?? null;

        if ($date !== null && (blank($current) || $current === $this->suggestedFirstDate)) {
            $this->data['options']['first_date'] = $date;
            $this->suggestedFirstDate = $date;
        }
    }
}
```

- [ ] **Step 4: Run to see them pass**

Run: `php artisan test --compact tests/Feature/PlanTravels/PlanTravelsWizardTest.php tests/Feature/ToolWizardPageTest.php`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty && git add -A && git commit -m "Plan travels: the first date follows the chosen plan export in the wizard"
```

---

### Task 6: Run page, CSV download and the sandbox for app links

**Files:**
- Create: `resources/views/filament/tool-runs/plan_travels.blade.php`, `tests/Feature/PlanTravels/PlanTravelsRunPageTest.php`
- Modify: `app/Http/Controllers/ToolRunPageController.php` (lines 17 to 28, 61 to 73, 98), `routes/web.php` (after line 28)

**Interfaces (Produces):**
```php
Route::get('download/csv', 'downloadCsv')->name('tool-runs.download-csv');  // serves result['csv'], 404 without it
ToolRunPageController::SANDBOX_WITH_APP_LINKS  // SANDBOX + ' allow-popups-to-escape-sandbox', Plan travels only
// fileName(): date part is summary['week'] ?? summary['first_date'] ?? run_date, so the CSV is plan_travels_<account>_<dataset>_<first date>.csv
```

- [ ] **Step 1: Write the failing tests**

`tests/Feature/PlanTravels/PlanTravelsRunPageTest.php`:
```php
<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user)->state(['account_id' => 'SATE']))->create(['name' => 'NORTH']);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::PlanTravels, 'run_date' => '2026-10-06',
        'result' => ['html' => '<!doctype html><html><body>Plan travels page</body></html>', 'csv' => "Resource ID,Flag\nR1,Review\n", 'data' => ['meta' => [], 'legs' => [['resource_id' => 'R1', 'flag' => 'Review']]]],
        'summary' => ['leg_count' => 1781, 'resource_count' => 412, 'review_count' => 37, 'missing_count' => 4, 'first_date' => '2026-10-05', 'last_date' => '2026-10-09', 'days' => 5, 'google' => null, 'line' => '1,781 legs for 412 resources, 2026-10-05 to 2026-10-09'],
    ]);
    $this->actingAs($this->user);
});

it('shows the summary line, the legs, Review and Missing badges, both downloads and the page', function () {
    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertOk()
        ->assertSee('1,781 legs for 412 resources, 2026-10-05 to 2026-10-09')
        ->assertSee('1,781 legs')->assertSee('37 to review')->assertSee('4 missing a time')
        ->assertSee('Download CSV')->assertSee('Download JSON')
        ->assertSeeHtml(e(route('tool-runs.download-csv', [$this->dataset, $this->run])))
        ->assertSeeHtml('sandbox="allow-scripts allow-downloads allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"')
        ->assertSeeHtml(e(route('tool-runs.page', [$this->dataset, $this->run])));
});

it('shows the kept legs and the reason on a run that failed at the Google step', function () {
    $this->run->update(['status' => ToolRunStatus::Failed, 'error_message' => 'The Google Routes check did not finish: Google Routes is not configured on pso-services.', 'warnings' => ['Google Routes was not checked, so the legs have no Google columns: Google Routes is not configured on pso-services.']]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertSee('This run did not finish')
        ->assertSee('The Google Routes check did not finish: Google Routes is not configured on pso-services.')
        ->assertSee('Download CSV')
        ->assertSeeHtml(e(route('tool-runs.page', [$this->dataset, $this->run])));
});

it('serves the CSV named after the account, dataset and first date', function () {
    $this->get(route('tool-runs.download-csv', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertDownload('plan_travels_SATE_NORTH_2026-10-05.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertStreamedContent("Resource ID,Flag\nR1,Review\n");
    $this->get(route('tool-runs.download-json', [$this->dataset, $this->run]))
        ->assertOk()->assertDownload('plan_travels_SATE_NORTH_2026-10-05.json');
});

it('keeps the CSV behind the panel login and the owner', function () {
    $other = ToolRun::factory()->for(Dataset::factory(), 'scope')->create(['tool' => ToolId::PlanTravels, 'result' => ['html' => '<p>x</p>', 'csv' => 'a']]);
    $this->get(route('tool-runs.download-csv', [$other->scope_id, $other]))->assertNotFound();

    $noCsv = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::PlanTravels, 'result' => ['html' => '<p>x</p>']]);
    $this->get(route('tool-runs.download-csv', [$this->dataset, $noCsv]))->assertNotFound();

    auth()->logout();
    $this->get(route('tool-runs.download-csv', [$this->dataset, $this->run]))->assertRedirect();
});

it('serves the plan travels page with links that can leave the sandbox', function () {
    $this->get(route('tool-runs.page', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation allow-popups-to-escape-sandbox');
});

it('keeps the other tools on the unchanged sandbox', function () {
    $audit = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => ['html' => '<p>audit</p>']]);

    $this->get(route('tool-runs.page', [$this->dataset, $audit]))
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation');
});
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/PlanTravels/PlanTravelsRunPageTest.php`
Expected: FAIL, route `tool-runs.download-csv` not defined.

- [ ] **Step 3: Implement**

`routes/web.php`, after line 28:
```php
        Route::get('download/csv', 'downloadCsv')->name('tool-runs.download-csv');
```

`app/Http/Controllers/ToolRunPageController.php`: add `use App\Enums\Tool as ToolId;`; after the `SANDBOX` constant (line 17):
```php
    /** Plan travels links to the app's own Travel Analyzer: the new tab must leave the sandbox to use the session. */
    public const SANDBOX_WITH_APP_LINKS = self::SANDBOX.' allow-popups-to-escape-sandbox';
```
in `page()` (line 25) replace the CSP line with:
```php
            'Content-Security-Policy' => $toolRun->tool === ToolId::PlanTravels ? self::SANDBOX_WITH_APP_LINKS : self::SANDBOX,
```
after `downloadHistoryCsv()` (line 59):
```php
    public function downloadCsv(string $dataset, string $run): SymfonyResponse
    {
        return $this->csv($dataset, $run, 'csv', null);
    }
```
`csv()` takes `?string $suffix` and builds the name as:
```php
        $name = self::fileName($datasetModel, $toolRun, 'csv');
        if ($suffix !== null) {
            $name = str_replace('.csv', "_{$suffix}.csv", $name);
        }

        return response()->streamDownload(static function () use ($text): void {
            echo $text;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
```
and in `fileName()` (line 98):
```php
        $date = $run->summary['week'] ?? $run->summary['first_date'] ?? $run->run_date?->toDateString() ?? 'undated';
```

`resources/views/filament/tool-runs/plan_travels.blade.php`:
```blade
@php
    $dataset = $run->scope;
    $summary = $run->summary ?? [];
    $line = $summary['line'] ?? null;
    $hasPage = filled($run->result['html'] ?? null);
    $hasCsv = is_string($run->result['csv'] ?? null);
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-2">
            @if (filled($line))
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $line }}</p>
            @endif
            <div class="flex flex-wrap gap-2 text-sm font-medium">
                <x-filament::badge color="gray">{{ number_format((int) ($summary['leg_count'] ?? 0)) }} legs</x-filament::badge>
                <x-filament::badge color="warning">{{ number_format((int) ($summary['review_count'] ?? 0)) }} to review</x-filament::badge>
                <x-filament::badge color="danger">{{ number_format((int) ($summary['missing_count'] ?? 0)) }} missing a time</x-filament::badge>
            </div>
        </div>
        @if ($hasPage)
        <div class="flex flex-wrap gap-2">
            @if ($hasCsv)
                <x-filament::button tag="a" href="{{ route('tool-runs.download-csv', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download CSV</x-filament::button>
            @endif
            <x-filament::button tag="a" href="{{ route('tool-runs.download-json', [$dataset, $run]) }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">Download JSON</x-filament::button>
        </div>
        @endif
    </div>
</x-filament::section>

{{-- The page plan_travels.py rendered. Sandboxed with no same-origin, so its scripts cannot reach the app;
     popups may leave the sandbox so "Check in PSO" opens a working Travel Analyzer in a new tab. --}}
@if ($hasPage)
<iframe
    src="{{ route('tool-runs.page', [$dataset, $run]) }}"
    title="Plan travels"
    sandbox="allow-scripts allow-downloads allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
    class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    style="height: calc(100vh - 14rem); min-height: 600px;"
></iframe>
@else
<p class="text-sm text-gray-600 dark:text-gray-400">The plan travels page is not available for this run.</p>
@endif
```

- [ ] **Step 4: Run to see them pass**

Run: `php artisan test --compact tests/Feature/PlanTravels/PlanTravelsRunPageTest.php tests/Feature/ActivityTrace tests/Feature/LoadAudit tests/Feature/RegionAvailability`
Expected: all pass (the trace's `_booking.csv` and `_history.csv` names are unchanged, and the load audit and region availability CSP assertions still see the plain sandbox).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty && git add -A && git commit -m "Plan travels: run page, CSV download and app links from the sandboxed page"
```

---

### Task 7: Travel Analyzer prefill from "Check in PSO"

**Files:**
- Create: `tests/Feature/PlanTravels/TravelAnalyzerPrefillTest.php`
- Modify: `app/Filament/Pages/TravelAnalyzer.php` (`mount()` lines 51 to 56)

**Interfaces (Produces):**
```php
/** @return array{lat_from: float, long_from: float, lat_to: float, long_to: float}|array{} all four from the query, or nothing */
public static function prefillFromQuery(array $query): array
// mount() fills travel_form (statePath 'data', line 168) with it when not empty.
```
The link the pso-tools page writes is `/travel-analyzer?lat_from=&long_from=&lat_to=&long_to=` (spec 6.5); the panel path is `/` (`app/Providers/Filament/*` line 76), so the page lives at `/travel-analyzer`, guarded by a test.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/PlanTravels/TravelAnalyzerPrefillTest.php`:
```php
<?php

use App\Filament\Pages\TravelAnalyzer;
use Livewire\Livewire;

it('lives at the path the plan travels page links to', function () {
    expect(parse_url(TravelAnalyzer::getUrl(), PHP_URL_PATH))->toBe('/travel-analyzer');
});

it('fills the four coordinates from a Check in PSO link', function () {
    Livewire::withQueryParams(['lat_from' => '52.123457', 'long_from' => '-106.5', 'lat_to' => '52.2', 'long_to' => '-106.6'])
        ->test(TravelAnalyzer::class)
        ->assertSet('data.lat_from', 52.123457)
        ->assertSet('data.long_from', -106.5)
        ->assertSet('data.lat_to', 52.2)
        ->assertSet('data.long_to', -106.6);
});

it('ignores the query when a value is missing, out of range or not a number', function (array $query) {
    expect(TravelAnalyzer::prefillFromQuery($query))->toBe([]);

    Livewire::withQueryParams($query)->test(TravelAnalyzer::class)->assertSet('data.lat_from', null);
})->with([
    'three of four' => [['lat_from' => '52.1', 'long_from' => '-106.5', 'lat_to' => '52.2']],
    'latitude over 90' => [['lat_from' => '91', 'long_from' => '-106.5', 'lat_to' => '52.2', 'long_to' => '-106.6']],
    'longitude under -180' => [['lat_from' => '52.1', 'long_from' => '-180.5', 'lat_to' => '52.2', 'long_to' => '-106.6']],
    'text' => [['lat_from' => 'north', 'long_from' => '-106.5', 'lat_to' => '52.2', 'long_to' => '-106.6']],
    'an array' => [['lat_from' => ['52.1'], 'long_from' => '-106.5', 'lat_to' => '52.2', 'long_to' => '-106.6']],
]);

it('opens empty without a query, as before', function () {
    Livewire::test(TravelAnalyzer::class)->assertOk()->assertSet('data.lat_from', null);
});
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/PlanTravels/TravelAnalyzerPrefillTest.php`
Expected: FAIL, `prefillFromQuery` undefined and `data.lat_from` null for the valid link.

- [ ] **Step 3: Implement**

`app/Filament/Pages/TravelAnalyzer.php`, replace `mount()` (lines 51 to 56) and add the helper below it:
```php
    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();
        $this->isAuthenticationRequired = true;
        $this->env_form->fill();

        // "Check in PSO" from a Plan travels run opens this page with the leg's coordinates in the query.
        $prefill = self::prefillFromQuery(request()->query());
        if ($prefill !== []) {
            $this->travel_form->fill($prefill);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, float> all four coordinates, or nothing when one is missing, not a number or out of range
     */
    public static function prefillFromQuery(array $query): array
    {
        $values = [];
        foreach (['lat_from' => 90.0, 'long_from' => 180.0, 'lat_to' => 90.0, 'long_to' => 180.0] as $key => $limit) {
            $raw = $query[$key] ?? null;
            if (! is_string($raw) || ! is_numeric(trim($raw)) || abs((float) trim($raw)) > $limit) {
                return [];
            }
            $values[$key] = (float) trim($raw);
        }

        return $values;
    }
```

- [ ] **Step 4: Run to see them pass**

Run: `php artisan test --compact tests/Feature/PlanTravels/TravelAnalyzerPrefillTest.php tests/Feature/TravelAnalyzerRandomNycSpotsTest.php tests/Feature/TravelAnalyzerSendToPsoTest.php`
Expected: all pass. If `Livewire::withQueryParams` is missing in Livewire 4, use `$this->get(TravelAnalyzer::getUrl([...]))->assertSeeLivewire(TravelAnalyzer::class)` with `->assertSee('52.123457')` for the valid link and keep the `prefillFromQuery` assertions as they are.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty && git add -A && git commit -m "Travel Analyzer: prefill the coordinates from a Plan travels Check in PSO link"
```

---

### Task 8: README, full suite and the browser check

**Files:**
- Modify: `README.md` (new section after "Trace an activity", line 139)

- [ ] **Step 1: README section**

Insert after the "Trace an activity" section:
```markdown
### Plan travels

How long are the planned travels in a plan export, and do they match real road times? Input: a **Plan** export
(required). Options: the first date (filled in from the export's own day) and how many days (1 to 31), and
**Check against Google Routes** (off by default), which shows the travel mode, routing preference and the review
thresholds (20 minutes, and a percent that is 0 for minutes only). The legs are built by Python from the pso-tools
checkout (`plan-travels/plan_travels.py`, found through `TOOLS_PSO_TOOLS_PATH`). With Google on, every distinct trip
goes to pso-services `POST /api/v2/routes/compute` in chunks of 200; pso-services holds the Google key and a shared
cache, so this app never sends a key and a trip already in the cache costs nothing. If pso-services fails, the run
fails with its message but still shows the legs without Google columns. The page lists every leg with filters, a
Google Maps link and **Check in PSO**, which opens the Travel Analyzer in a new tab with the leg's coordinates
filled in. Downloads: the CSV (the script's 30 columns, plus 11 Google columns when Google ran) and the JSON.
Timeouts: `TOOLS_PLAN_TRAVELS_TIMEOUT_SECONDS` (600, per script run) and `PSO_SERVICES_ROUTES_TIMEOUT` (300, per
chunk of 200 trips).
```

- [ ] **Step 2: Full suite and style**

Run: `php artisan test --compact` then `vendor/bin/pint --dirty`.
Expected: only the known `ExampleTest` failure. `grep -rnP "\x{2014}|\bplaced\b" app/Tools/PlanTravels resources/views/filament/tool-runs/plan_travels.blade.php README.md` finds nothing new (no em dashes, "Allocated" wording).

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "Plan travels: README"
```

- [ ] **Step 4: Browser check for Ish (after the pso-tools and pso-services work is deployed and the cache seeded)**

1. Tools, Plan travels: the Plan export row only; the first date shows the export's day; Google fields appear only with the toggle on.
2. Run on the SaskTel plan export (`north_travel_1005.xml`) for 2026-10-05, 5 days, Google off: 1,781 legs, the page in the frame, Download CSV opens the same rows as the golden CSV.
3. Same with Google on: progress moves through "Checking trips with Google, n of 9"; most trips come from the cache; Review and Missing badges match the page tiles.
4. Click **Check in PSO** on a leg: a new tab opens the Travel Analyzer with the four coordinates filled in and the user still logged in.
5. With the Google key removed on pso-services: the run fails with "The Google Routes check did not finish: Google Routes is not configured on pso-services." and still shows the legs.

---

## Self-review

| Spec requirement | Task |
|---|---|
| 6.1 `PlanTravelsTool` implements the contract, required kind plan, no optional kinds, dataset scope, wizard flow | 4 (`is registered and asks for a plan export only`; scope comes from `Tool::scope()`) |
| 6.2 First date, required, default from the export summary | 4 (option field, fallback in `run()`), 5 (wizard default) |
| 6.2 Days 1 to 31, default 1 | 4, 5 |
| 6.2 Google toggle default off, helper text names the cost | 4, 5 |
| 6.2 Travel mode and routing preference selects with defaults, shown with the toggle | 4, 5 |
| 6.2 Review minutes 20 and percent 0, shown with the toggle | 4, 5 |
| 6.3 step 1 build through `ScriptRun::execute`, timeout from `config/tools.php` default 600, progress "Building legs" | 1 (config, command), 4 |
| 6.3 step 2 distinct pairs, chunks of 200 through `PsoServicesRoutesClient`, progress per chunk | 2, 4 |
| 6.3 step 2 chunk failure fails the run with the error, legs kept and the reason shown | 3, 4, 6 |
| 6.3 step 3 `google.json` keyed by the legs' `route_key` copied verbatim, values, merge-google, progress "Merging Google results" | 2, 4 |
| 6.3 step 4 `html`, `csv`, `data` in the encrypted result; counts-only summary; warnings from legs.json | 4 |
| 6.4 Run page: summary line, badges (legs, Review, Missing), Download CSV and JSON, sandboxed iframe | 6 |
| 6.4 Route `tool-runs.download-csv` behind panel auth | 6 (file name keeps the house `<tool>_<account>_<dataset>_<date>` pattern, date = first date) |
| 6.5 Check in PSO opens the Travel Analyzer prefilled; mount reads all four valid values; popups allowed | 6 (sandbox token), 7 |
| 6.6 Tests: command builder both commands (with and without `--dataset-name`) | 1 |
| 6.6 Tests: fake checkout with stub script and `Http::fake`: Google off, on with cache and google sources, 503 keeps legs, chunking at 200 | 4 |
| 6.6 Tests: run page badges and buttons; CSV route behind auth; analyzer prefill | 6, 7 |
| 5.3 request and response shape, `Accept: application/json` | 2 |
| 7 no Google key sent; leg data only in the encrypted result | 2 (`! hasHeader('X-Goog-Api-Key')`), 4 (summary counts only) |
| 8 progress per chunk on long runs | 4 |
| Config and `.env.example` entries, README | 1, 8 |

Gaps found and fixed while writing: the existing wizard test used Plan travels as its unregistered example (Task 4 switches it to the Daily schedule review); `RunToolJob` threw away results on any failure, which the spec's "legs kept" needs (Task 3); the sandboxed iframe would have opened the Travel Analyzer inside the sandbox (Task 6 adds `allow-popups-to-escape-sandbox` for this tool only); the script reports only the stage `reading`, which `ProgressLine` labels for the trace, so `ScriptRun::execute` takes a `progressLabel` from the caller (Task 4). Placeholders: none. Type consistency: `PlanTravelsCommand::build/mergeGoogle`, `TripKeys::trips/split`, `PsoServicesRoutesClient::compute` and the result keys `html`, `csv`, `data` and summary keys are the same in Tasks 4, 6 and the tests.
