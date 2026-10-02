<?php

use App\Support\SysCompare\Comparer;
use App\Support\SysCompare\ComparisonResult;
use App\Support\SysCompare\ParamDefinitions;
use App\Support\SysCompare\ParameterCatalog;
use App\Support\SysCompare\Render\CsvBundle;
use App\Support\SysCompare\Render\HtmlReport;
use App\Support\SysCompare\Render\XlsxWorkbook;
use App\Support\SysCompare\RunSummary;
use App\Support\SysCompare\SysEnvironment;
use App\Support\SysCompare\SysFileReader;
use App\Support\SysCompare\ValueNormalizer;
use Tests\Support\SysFileBuilder;

const CATALOG_CSV = <<<'CSV'
application,parameter_id,access,data_type,group,class,default_value,description
DSE,Flag,LOADUPDATE,BOOLEAN,X,ORG,false,Turns the flag on or off.
DSE,Interval,LOADUPDATE,TIMESPAN,X,ORG,PT0H5M0S,How often it runs.
DSE,Count,LOADUPDATE,INTEGER,X,ORG,10,How many.
DSE,Ratio,LOADUPDATE,DOUBLE,X,ORG,1.5,A ratio.
DSE,Label,LOADUPDATE,STRING,X,ORG,Default,"A label, with a comma
and a second line."
DSE,Blank,LOADUPDATE,STRING,X,ORG,,Defaults to nothing.
DSE,SecretKey,LOADUPDATE,MASKED,X,ORG,sk_default_FAKE,A secret.
DSE,Shared,LOADUPDATE,STRING,X,ORG,from-dse,Shared in DSE.
PSW,Shared,LOADUPDATE,STRING,X,ORG,from-psw,Shared in PSW.
CSV;

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/syscompare-*')) ?: [] as $file) {
        @unlink($file);
    }
});

function effectiveCatalog(?string $psoVersion = null): ParameterCatalog
{
    $path = storage_path('framework/testing/syscompare-catalog-'.bin2hex(random_bytes(4)).'.csv');
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, "\xEF\xBB\xBF".CATALOG_CSV);

    return ParameterCatalog::fromCsv($path, $psoVersion);
}

/**
 * @param  array<string, SysFileBuilder>  $builders
 */
function compareEffective(array $builders, ?ParameterCatalog $catalog = null, ?ParamDefinitions $definitions = null): ComparisonResult
{
    $reader = app(SysFileReader::class);
    $environments = [];

    foreach ($builders as $name => $builder) {
        $environments[] = new SysEnvironment($name, $reader->read($builder->write($name), "{$name}.xml"));
    }

    return app(Comparer::class)->compare($environments, array_key_first($builders), $definitions, catalog: $catalog ?? effectiveCatalog());
}

/**
 * @return array{0: string, 1: list<string>}
 */
function effectiveRow(ComparisonResult $result, string $parameter, string $profile = 'DEFAULT', string $tab = 'Parameters'): array
{
    $row = collect($result->tab($tab)->rows())->first(static fn ($candidate): bool => $candidate->keyValues[0] === $profile && $candidate->keyValues[1] === $parameter);

    expect($row)->not->toBeNull("{$profile} / {$parameter} not found");

    return [$row->status, $row->cellValues];
}

it('normalises values only to decide sameness, by data type', function (string $type, string $left, string $right, bool $same): void {
    expect(ValueNormalizer::canonical($left, $type) === ValueNormalizer::canonical($right, $type))->toBe($same);
})->with([
    'boolean ignores case' => ['BOOLEAN', 'True', 'true', true],
    'boolean differs' => ['BOOLEAN', 'True', 'false', false],
    'integer is numeric' => ['INTEGER', ' 05 ', '5', true],
    'integer differs' => ['INTEGER', '5', '6', false],
    'double is numeric' => ['DOUBLE', '1', '1.0', true],
    'double differs' => ['DOUBLE', '1.5', '1.6', false],
    'timespan minutes' => ['TIMESPAN', 'PT5M', 'PT0H5M0S', true],
    'timespan days and hours' => ['TIMESPAN', 'P2D', 'PT48H', true],
    'timespan differs' => ['TIMESPAN', 'PT5M', 'PT6M', false],
    'string is exact' => ['STRING', 'Default', 'DEFAULT', false],
    'string keeps whitespace' => ['STRING', 'a', 'a ', false],
    'unknown type is exact' => ['', 'True', 'true', false],
    'unparseable timespan falls back to exact' => ['TIMESPAN', 'soon', 'SOON', false],
]);

it('reads the catalog: BOM, quoted multi-line descriptions, applications and blank defaults', function (): void {
    $catalog = effectiveCatalog();

    expect($catalog->isEmpty())->toBeFalse()
        ->and($catalog->entry('label')->description)->toBe("A label, with a comma\nand a second line.")
        ->and($catalog->entry('BLANK')->defaultValue)->toBe('')
        ->and($catalog->entry('Shared', 'PSW')->defaultValue)->toBe('from-psw')
        ->and($catalog->entry('Shared', 'DSE')->defaultValue)->toBe('from-dse')
        ->and($catalog->entry('Shared', 'SWB')->defaultValue)->toBe('from-dse')
        ->and($catalog->entry('NotThere'))->toBeNull()
        ->and(ParameterCatalog::empty()->isEmpty())->toBeTrue()
        ->and(ParameterCatalog::fromCsv('/does/not/exist.csv')->isEmpty())->toBeTrue();
});

it('ships a catalog with the reference parameters', function (): void {
    $catalog = ParameterCatalog::builtIn();

    expect($catalog->count())->toBeGreaterThan(600)
        ->and($catalog->entry('AllowSplitTravel')->defaultValue)->toBe('True')
        ->and($catalog->entry('GpsFrequency')->dataType)->toBe('TIMESPAN');
});

it('resolves explicit value, then catalog default, then absent', function (): void {
    $result = compareEffective([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'Count', '12', 'DSE')->parameter('DEFAULT', 'Unlisted', 'x', 'DSE'),
        'B' => SysFileBuilder::make()->profile('DEFAULT')->parameter('DEFAULT', 'Unlisted', 'x', 'DSE')->parameter('DEFAULT', 'Count', '12', 'DSE'),
        'C' => SysFileBuilder::make()->profile('DEFAULT')->parameter('DEFAULT', 'Other', '1', 'DSE'),
    ]);

    expect(effectiveRow($result, 'Count'))->toBe(['DIFF', ['12', '12', '(default: 10)']])
        ->and(effectiveRow($result, 'Unlisted'))->toBe(['DIFF', ['x', 'x', '(absent)']]);
});

it('marks rows that differ only because of defaults, and does not count them', function (): void {
    $result = compareEffective([
        'PROD' => SysFileBuilder::make()->parameter('DEFAULT', 'Flag', 'false', 'DSE')->parameter('DEFAULT', 'Interval', 'PT5M', 'DSE')->parameter('DEFAULT', 'Count', '10', 'DSE'),
        'ACC' => SysFileBuilder::make()->parameter('DEFAULT', 'Other', '1', 'DSE'),
        'TST' => SysFileBuilder::make()->parameter('DEFAULT', 'Flag', 'False', 'DSE')->parameter('DEFAULT', 'Count', '11', 'DSE'),
    ]);

    $tally = collect($result->tally)->firstWhere('area', 'Parameters');
    $statuses = collect($result->tab('Parameters')->rows())->mapWithKeys(static fn ($row): array => [$row->keyValues[1] => $row->status]);

    expect($statuses['Flag'])->toBe('Same (default)')
        ->and($statuses['Interval'])->toBe('Same (default)')
        ->and($statuses['Count'])->toBe('DIFF')
        ->and($statuses['Other'])->toBe('DIFF')
        ->and($tally->notIdenticalAcrossAll)->toBe(2)
        ->and($tally->differencesByEnvironment['ACC'])->toBe(1)
        ->and($tally->differencesByEnvironment['TST'])->toBe(1)
        ->and($result->quickRead->defaultedParameterRows)->toBe(2)
        ->and($result->quickRead->defaultedParameterNames)->toBe(['Flag', 'Interval']);
});

it('only highlights real differences against the baseline', function (): void {
    $result = compareEffective([
        'PROD' => SysFileBuilder::make()->parameter('DEFAULT', 'Flag', 'false', 'DSE')->parameter('DEFAULT', 'Count', '10', 'DSE'),
        'ACC' => SysFileBuilder::make()->profile('DEFAULT')->parameter('DEFAULT', 'Count', '11', 'DSE'),
    ]);

    $flag = collect($result->tab('Parameters')->rows())->first(static fn ($row): bool => $row->keyValues[1] === 'Flag');
    $count = collect($result->tab('Parameters')->rows())->first(static fn ($row): bool => $row->keyValues[1] === 'Count');

    expect($flag->differsFromBaseline(1, 0))->toBeFalse()
        ->and($count->differsFromBaseline(1, 0))->toBeTrue();
});

it('does not let a profile inherit from the DEFAULT profile, and says when a profile does not exist', function (): void {
    $result = compareEffective([
        'PROD' => SysFileBuilder::make()->parameter('DEFAULT', 'Label', 'FromDefault', 'DSE')->profile('OTHER'),
        'TST' => SysFileBuilder::make()->parameter('DEFAULT', 'Label', 'FromDefault', 'DSE')->parameter('OTHER', 'Label', 'FromDefault', 'DSE'),
        'ACC' => SysFileBuilder::make()->parameter('DEFAULT', 'Label', 'FromDefault', 'DSE'),
    ]);

    expect(effectiveRow($result, 'Label', 'OTHER'))->toBe(['DIFF', ['(default: Default)', 'FromDefault', '(no profile)']]);
});

it('matches the catalog entry by application type', function (): void {
    $result = compareEffective([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'Shared', 'from-psw', 'PSW')->profile('DEFAULT'),
        'B' => SysFileBuilder::make()->profile('DEFAULT')->parameter('DEFAULT', 'Other', '1'),
    ]);

    $row = collect($result->tab('Parameters')->rows())->first(static fn ($candidate): bool => $candidate->keyValues[1] === 'Shared');

    expect($row->keyValues[2])->toBe('PSW')
        ->and($row->cellValues)->toBe(['from-psw', '(default: from-psw)'])
        ->and($row->status)->toBe('Same (default)')
        ->and($row->extra['Default'])->toBe('from-psw');
});

it('shows (default: blank) for an empty default', function (): void {
    $result = compareEffective([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'Blank', 'x', 'DSE'),
        'B' => SysFileBuilder::make()->profile('DEFAULT')->parameter('DEFAULT', 'Other', '1'),
    ]);

    expect(effectiveRow($result, 'Blank')[1])->toBe(['x', '(default: blank)']);
});

it('never shows or stores a secret, or the default of a masked key', function (): void {
    $secret = 'sk_live_FAKE_EFFECTIVE_0123456789';

    $result = compareEffective([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'SecretKey', $secret, 'DSE'),
        'B' => SysFileBuilder::make()->profile('DEFAULT')->parameter('DEFAULT', 'Other', '1'),
    ]);

    [$status, $cells] = effectiveRow($result, 'SecretKey');
    $row = collect($result->tab('Parameters')->rows())->first(static fn ($candidate): bool => $candidate->keyValues[1] === 'SecretKey');

    expect($cells)->toBe(['[API key set]', '(default: [API key set])'])
        ->and($status)->toBe('DIFF')
        ->and($row->extra['Default'])->toBe('[API key set]')
        ->and(serialize($result))->not->toContain($secret)
        ->and(serialize($result))->not->toContain('sk_default_FAKE');
});

it('still tells two different keys apart without keeping either', function (): void {
    $result = compareEffective([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'SecretKey', 'FAKE-KEY-ONE', 'DSE'),
        'B' => SysFileBuilder::make()->parameter('DEFAULT', 'SecretKey', 'FAKE-KEY-TWO', 'DSE'),
    ]);

    expect(effectiveRow($result, 'SecretKey')[0])->toBe('DIFF')
        ->and($result->apiKeyValuesDiffer)->toBeTrue()
        ->and(serialize($result))->not->toContain('FAKE-KEY-ONE')
        ->and(serialize($result))->not->toContain('FAKE-KEY-TWO');
});

it('falls back to raw comparison and says so loudly when the catalog is not available', function (): void {
    $builders = [
        'PROD' => SysFileBuilder::make()->parameter('DEFAULT', 'Flag', 'false', 'DSE'),
        'TST' => SysFileBuilder::make()->profile('DEFAULT')->parameter('DEFAULT', 'Other', '1', 'DSE'),
    ];

    $result = compareEffective($builders, ParameterCatalog::empty());
    $html = app(HtmlReport::class)->render($result);

    expect($result->defaultsApplied)->toBeFalse()
        ->and(effectiveRow($result, 'Flag'))->toBe(['DIFF', ['false', '(absent)']])
        ->and($result->quickRead->defaultedParameterRows)->toBe(0)
        ->and($html)->toContain('Parameter defaults were NOT applied')
        ->and(RunSummary::fromResult($result)->notes[0])->toContain('NOT applied');

    $applied = compareEffective($builders);

    expect($applied->defaultsApplied)->toBeTrue()
        ->and(app(HtmlReport::class)->render($applied))->not->toContain('Parameter defaults were NOT applied');
});

it('hides Same (default) rows in the HTML unless asked, and explains them in the quick read', function (): void {
    $result = compareEffective([
        'PROD' => SysFileBuilder::make()->parameter('DEFAULT', 'Flag', 'false', 'DSE')->parameter('DEFAULT', 'Count', '10', 'DSE')->parameter('DEFAULT', 'Interval', 'PT5M', 'DSE'),
        'TST' => SysFileBuilder::make()->parameter('DEFAULT', 'Count', '11', 'DSE'),
    ]);

    $hidden = app(HtmlReport::class)->render($result);
    $everything = app(HtmlReport::class)->render($result, showSameRows: true);

    expect($hidden)->not->toContain('<td class="k">Flag</td>')
        ->and($hidden)->toContain('<td class="k">Count</td>')
        ->and($hidden)->toContain('2 more parameter rows only look different in the export')
        ->and($hidden)->toContain('Flag, Interval')
        ->and($hidden)->toContain('1 parameter rows have different effective values')
        ->and($hidden)->toContain('Rows that differ only because of defaults are marked Same (default)')
        ->and($everything)->toContain('<td class="k">Flag</td>')
        ->and($everything)->toContain('<td class="muted">(default: false)</td>');
});

it('writes the Default column and the Same (default) status to the CSV and the workbook', function (): void {
    $result = compareEffective([
        'PROD' => SysFileBuilder::make()->parameter('DEFAULT', 'Flag', 'false', 'DSE')->parameter('DEFAULT', 'Blank', 'x', 'DSE'),
        'TST' => SysFileBuilder::make()->profile('DEFAULT')->parameter('DEFAULT', 'Other', '1', 'DSE'),
    ]);

    $csv = app(CsvBundle::class)->files($result)['01_Parameters.csv'];

    expect($csv)->toContain('"Status","Default","Definition"')
        ->and($csv)->toContain('"DEFAULT","Flag","DSE","false","(default: false)","Same (default)","false"')
        ->and($csv)->toContain('"(blank)"');

    $path = storage_path('framework/testing/syscompare-effective.xlsx');
    app(XlsxWorkbook::class)->write($result, $path);

    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet3.xml');
    $zip->close();

    expect($sheet)->toContain('Same (default)')->toContain('>Default<')->toContain('(default: false)');
});

it('builds a definition from the official description, a Definition and a Note', function (): void {
    $definitions = new ParamDefinitions(
        [
            // a Definition replaces the official text (and takes its own Note)
            'Flag' => ['definition' => 'My own flag text.', 'note' => 'Careful.', 'basis' => 'KB: example'],
            // a Note is appended to the official description
            'Count' => ['note' => 'Confirmed by IFS.', 'basis' => 'KB: example'],
            // a Note on a parameter the catalog does not know is ignored: there is nothing to append it to
            'Unlisted' => ['note' => 'Orphan note.', 'basis' => 'KB: example'],
            'Guess' => ['definition' => 'Probably this.', 'basis' => 'Inference (not in KB)'],
        ],
        [['pattern' => 'Seconds$', 'text' => 'A time setting.', 'basis' => 'Inference (name pattern)']],
    );

    $catalog = effectiveCatalog();

    expect($definitions->find('Flag', 'DSE', $catalog)->text)->toBe('My own flag text. Note: Careful.')
        ->and($definitions->find('Flag', 'DSE', $catalog)->basis)->toBe('KB: example')
        ->and($definitions->find('Count', 'DSE', $catalog)->text)->toBe('How many. Note: Confirmed by IFS.')
        ->and($definitions->find('Count', 'DSE', $catalog)->basis)->toBe('Schema reference + KB: example')
        ->and($definitions->find('Count', 'DSE', $catalog)->displayText())->toBe('How many. Note: Confirmed by IFS.')
        ->and($definitions->find('Interval', 'DSE', $catalog)->text)->toBe('How often it runs.')
        ->and($definitions->find('Interval', 'DSE', $catalog)->basis)->toBe('Schema reference')
        ->and($definitions->find('Unlisted', 'DSE', $catalog))->toBeNull()
        ->and($definitions->find('Guess', 'DSE', $catalog)->displayText())->toBe('[Inferred] Probably this.')
        ->and($definitions->find('RetrySeconds', 'DSE', $catalog)->basis)->toBe('Inference (name pattern)')
        ->and($definitions->find('Nothing', 'DSE', $catalog))->toBeNull();
});

it('closes the official sentence before appending a note', function (): void {
    $definitions = new ParamDefinitions(['Blank' => ['note' => 'A note.', 'basis' => 'KB: example']]);

    expect($definitions->find('Blank', 'DSE', effectiveCatalog())->text)->toBe('Defaults to nothing. Note: A note.');

    $withoutPeriod = new ParamDefinitions(['Flag' => ['note' => 'Later.', 'basis' => 'KB: example']]);
    $path = storage_path('framework/testing/syscompare-period-'.bin2hex(random_bytes(4)).'.csv');
    file_put_contents($path, "application,parameter_id,access,data_type,group,class,default_value,description\nDSE,Flag,LOADUPDATE,BOOLEAN,X,ORG,false,Turns it on\n");

    expect($withoutPeriod->find('Flag', 'DSE', ParameterCatalog::fromCsv($path))->text)->toBe('Turns it on. Note: Later.');
});

it('ships the built-in definitions and name patterns', function (): void {
    $definitions = ParamDefinitions::builtIn();
    $catalog = ParameterCatalog::builtIn();

    // 28 parameters the catalog does not cover get a full Definition ...
    $definitionOnly = $definitions->find('CascadeSchedulingObjectDeletions', '', $catalog);
    // ... and 32 catalog parameters get a Note appended to the official description
    $withNote = $definitions->find('AllowAllocateBeforeCommitted', '', $catalog);

    expect($catalog->entry('CascadeSchedulingObjectDeletions'))->toBeNull()
        ->and($definitionOnly->text)->not->toBe('')
        ->and($definitionOnly->displayText())->toStartWith('[Inferred] ')
        ->and($withNote->text)->toContain(' Note: ')
        ->and($withNote->basis)->toStartWith('Schema reference + KB')
        ->and($definitions->find('PSWGanttBarColour', '', $catalog)->basis)->toBe('Inference (name pattern)');
});

it('only puts parameters in the template when nothing but a name-pattern guess (or nothing) describes them', function (): void {
    $definitions = new ParamDefinitions([], [['pattern' => 'Seconds$', 'text' => 'A time setting.', 'basis' => 'Inference (name pattern)']]);

    $result = compareEffective([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'Count', '1', 'DSE')->parameter('DEFAULT', 'RetrySeconds', '1', 'DSE')->parameter('DEFAULT', 'Mystery', '1', 'DSE'),
        'B' => SysFileBuilder::make()->parameter('DEFAULT', 'Count', '2', 'DSE')->parameter('DEFAULT', 'RetrySeconds', '2', 'DSE')->parameter('DEFAULT', 'Mystery', '2', 'DSE'),
    ], definitions: $definitions);

    expect($result->definitionTemplate)->toBe(['Mystery' => '', 'RetrySeconds' => 'A time setting.']);
});

it('resolves the travel parameters as effective values too', function (): void {
    $catalogCsv = storage_path('framework/testing/syscompare-travel-'.bin2hex(random_bytes(4)).'.csv');
    file_put_contents($catalogCsv, "application,parameter_id,access,data_type,group,class,default_value,description\nDSE,AllowSplitTravel,LOADUPDATE,BOOLEAN,TRAVEL,ORG,True,Splits travel.\n");

    $result = compareEffective([
        'PROD' => SysFileBuilder::make()->parameter('DEFAULT', 'AllowSplitTravel', 'True', 'DSE'),
        'TST' => SysFileBuilder::make()->profile('DEFAULT')->parameter('DEFAULT', 'TravelCalculationOption', 'StraightLine', 'DSE'),
    ], ParameterCatalog::fromCsv($catalogCsv));

    $row = collect($result->tab('Travel')->rows())->first(static fn ($candidate): bool => $candidate->keyValues[0] === 'Param: DEFAULT / AllowSplitTravel');

    expect($row->cellValues)->toBe(['True', '(default: True)'])
        ->and($row->status)->toBe('Same (default)');
});

function withVersion(SysFileBuilder $builder, string $latest): SysFileBuilder
{
    return $builder
        ->version('6.14.0', 'Upgrade from 6.13.0', '2024-12-02T02:39:11+00:00', 'ifs')
        ->version($latest, 'Update data', '2025-01-02T02:39:20+00:00', 'ifs');
}

it('records the PSO release the shipped catalog is for', function (): void {
    expect(ParameterCatalog::builtIn()->psoVersion)->toBe('6.14');
});

it('warns when an environment is on a different PSO release than the catalog, naming it', function (): void {
    $result = compareEffective([
        'PROD' => withVersion(SysFileBuilder::make()->parameter('DEFAULT', 'Flag', 'false', 'DSE'), '6.16.0.41'),
        'ACC' => withVersion(SysFileBuilder::make()->profile('DEFAULT'), '6.14.0.38'),
        'TST' => SysFileBuilder::make()->profile('DEFAULT'),
    ], effectiveCatalog('6.14'));

    $warning = (string) $result->catalogVersionWarning();

    expect($result->hasCatalogVersionMismatch())->toBeTrue()
        ->and($result->catalogVersion)->toBe('6.14')
        ->and($result->catalogVersionMismatches)->toBe(['PROD' => '6.16'])
        ->and($warning)->toContain('PSO 6.14')->toContain('PROD is on 6.16')->not->toContain('ACC')->not->toContain('TST')
        ->and($warning)->toContain('Same (default)');

    $html = app(HtmlReport::class)->render($result);

    expect($html)->toContain('Parameter defaults may not match these PSO versions')
        ->and($html)->toContain('Check the defaults:')
        ->and(RunSummary::fromResult($result)->notes)->toContain($warning);

    $path = storage_path('framework/testing/syscompare-versionwarning.xlsx');
    app(XlsxWorkbook::class)->write($result, $path);
    $zip = new ZipArchive;
    $zip->open($path);

    expect((string) $zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('CHECK THE PARAMETER DEFAULTS');

    $zip->close();
});

it('stays quiet when every environment is on the catalog release', function (): void {
    $result = compareEffective([
        'PROD' => withVersion(SysFileBuilder::make()->profile('DEFAULT'), '6.14.0.56'),
        'TST' => withVersion(SysFileBuilder::make()->profile('DEFAULT'), '6.14.0.38'),
    ], effectiveCatalog('6.14'));

    expect($result->hasCatalogVersionMismatch())->toBeFalse()
        ->and($result->catalogVersionWarning())->toBeNull()
        ->and(app(HtmlReport::class)->render($result))->not->toContain('may not match these PSO versions');
});

it('does not warn when the catalog release is unknown or there is no catalog', function (): void {
    $builders = [
        'PROD' => withVersion(SysFileBuilder::make()->profile('DEFAULT'), '6.16.0.41'),
        'TST' => withVersion(SysFileBuilder::make()->profile('DEFAULT'), '6.16.0.41'),
    ];

    $unknown = compareEffective($builders, effectiveCatalog());
    $none = compareEffective($builders, ParameterCatalog::empty());

    expect($unknown->catalogVersionWarning())->toBeNull()
        ->and($none->catalogVersionWarning())->toBeNull()
        ->and($none->defaultsApplied)->toBeFalse();
});
