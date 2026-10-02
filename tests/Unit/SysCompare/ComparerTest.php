<?php

use App\Support\SysCompare\Comparer;
use App\Support\SysCompare\ComparisonResult;
use App\Support\SysCompare\Exceptions\InvalidComparison;
use App\Support\SysCompare\ParamDefinitions;
use App\Support\SysCompare\ParameterCatalog;
use App\Support\SysCompare\SysEnvironment;
use App\Support\SysCompare\SysFileReader;
use Tests\Support\SysFileBuilder;

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/syscompare-*')) ?: [] as $file) {
        @unlink($file);
    }
});

/**
 * @param  array<string, SysFileBuilder>  $builders  environment name => fake sys file, in display order
 */
function compareBuilders(array $builders, ?string $baseline = null, ?ParamDefinitions $definitions = null): ComparisonResult
{
    $reader = app(SysFileReader::class);
    $environments = [];

    foreach ($builders as $name => $builder) {
        $environments[] = new SysEnvironment($name, $reader->read($builder->write($name), "{$name}.xml"));
    }

    return app(Comparer::class)->compare($environments, $baseline ?? array_key_first($builders), $definitions);
}

/**
 * @return list<string>
 */
function parameterValues(ComparisonResult $result, string $parameter): array
{
    $row = collect($result->tab('Parameters')->rows())->first(static fn ($candidate): bool => $candidate->keyValues[1] === $parameter);

    expect($row)->not->toBeNull("Parameter {$parameter} not found");

    return $row->cellValues;
}

it('keeps the environment order the user gave and marks the baseline', function (): void {
    $result = compareBuilders([
        'TST' => SysFileBuilder::make()->parameter('DEFAULT', 'A', '1'),
        'PROD' => SysFileBuilder::make()->parameter('DEFAULT', 'A', '2'),
        'ACC' => SysFileBuilder::make()->parameter('DEFAULT', 'A', '3'),
    ], baseline: 'PROD');

    expect($result->environmentNames())->toBe(['TST', 'PROD', 'ACC'])
        ->and($result->baselineName())->toBe('PROD')
        ->and(parameterValues($result, 'A'))->toBe(['1', '2', '3']);
});

it('counts the rows that differ from the chosen baseline', function (): void {
    $builders = [
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'One', 'x')->parameter('DEFAULT', 'Two', 'x'),
        'B' => SysFileBuilder::make()->parameter('DEFAULT', 'One', 'x')->parameter('DEFAULT', 'Two', 'y'),
        'C' => SysFileBuilder::make()->parameter('DEFAULT', 'One', 'y')->parameter('DEFAULT', 'Two', 'y'),
    ];

    $againstA = collect(compareBuilders($builders, 'A')->tally)->firstWhere('area', 'Parameters');
    $againstC = collect(compareBuilders($builders, 'C')->tally)->firstWhere('area', 'Parameters');

    expect($againstA->rowsCompared)->toBe(2)
        ->and($againstA->differencesByEnvironment)->toBe(['A' => 0, 'B' => 1, 'C' => 2])
        ->and($againstA->notIdenticalAcrossAll)->toBe(2)
        ->and($againstC->differencesByEnvironment)->toBe(['A' => 2, 'B' => 1, 'C' => 0]);
});

it('tells an absent parameter from an empty one and marks leading and trailing spaces', function (): void {
    $result = compareBuilders([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'Empty', '')->parameter('DEFAULT', 'Spaced', ' x '),
        'B' => SysFileBuilder::make()->parameter('DEFAULT', 'Spaced', 'x'),
    ]);

    expect(parameterValues($result, 'Empty'))->toBe(['(null)', '(absent)'])
        ->and(parameterValues($result, 'Spaced'))->toBe(["\u{2423}x\u{2423}", 'x']);
});

it('compares case-sensitively', function (): void {
    $result = compareBuilders([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'Mode', 'Auto'),
        'B' => SysFileBuilder::make()->parameter('DEFAULT', 'Mode', 'auto'),
    ]);

    $row = $result->tab('Parameters')->rows()[0];

    expect($row->status)->toBe('DIFF')
        ->and($row->differsFromBaseline(1, 0))->toBeTrue();
});

it('masks API key parameters and keeps the value out of every output cell', function (): void {
    $secret = 'sk_live_FAKE_0123456789abcdef';

    $result = compareBuilders([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'RoutingApiKey', $secret),
        'B' => SysFileBuilder::make()->parameter('DEFAULT', 'RoutingApiKey', $secret),
    ]);

    expect(parameterValues($result, 'RoutingApiKey'))->toBe(['[API key set]', '[API key set]'])
        ->and($result->apiKeyValuesDiffer)->toBeFalse()
        ->and(serialize($result->tabs))->not->toContain($secret)
        ->and(serialize($result->tableCounts))->not->toContain($secret);
});

it('warns when API key values differ between environments but still masks them', function (): void {
    $result = compareBuilders([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'RoutingApiKey', 'FAKE-KEY-ONE'),
        'B' => SysFileBuilder::make()->parameter('DEFAULT', 'RoutingApiKey', 'FAKE-KEY-TWO'),
    ]);

    expect($result->apiKeyValuesDiffer)->toBeTrue()
        ->and(parameterValues($result, 'RoutingApiKey'))->toBe(['[API key set]', '[API key set]'])
        ->and(serialize($result))->not->toContain('FAKE-KEY-ONE')
        ->and(serialize($result))->not->toContain('FAKE-KEY-TWO');
});

it('does not mask a key parameter that has no value', function (): void {
    $result = compareBuilders([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'RoutingApiKey', ''),
        'B' => SysFileBuilder::make()->parameter('DEFAULT', 'RoutingApiKey', 'FAKE'),
    ]);

    expect(parameterValues($result, 'RoutingApiKey'))->toBe(['(null)', '[API key set]']);
});

it('treats group names that differ only by case as one group and flags the spelling', function (): void {
    $result = compareBuilders([
        'PROD' => SysFileBuilder::make()->group('ST_Ops_Mgr')->groupPermission('ST_Ops_Mgr', 'ViewAll', true),
        'STG' => SysFileBuilder::make()->group('ST_Ops_MGR')->groupPermission('ST_Ops_MGR', 'ViewAll', true),
    ]);

    $presentAs = collect($result->tab('Groups')->rows())->firstWhere(static fn ($row): bool => $row->keyValues[1] === 'Present as');
    $permission = $result->tab('Group Permissions')->rows()[0];

    expect(collect($result->tab('Groups')->rows())->filter(static fn ($row): bool => $row->keyValues[1] === 'Present as'))->toHaveCount(1)
        ->and($presentAs->keyValues[0])->toBe('ST_Ops_Mgr')
        ->and($presentAs->cellValues)->toBe(['ST_Ops_Mgr', 'ST_Ops_MGR'])
        ->and($permission->status)->toBe('Same')
        ->and($permission->keyValues[2])->toBe('group spelling differs across environments');
});

it('shows allow and allow_edit as T / F, including explicit denies', function (): void {
    $result = compareBuilders([
        'A' => SysFileBuilder::make()->groupPermission('G', 'Administrator', false, true)->groupPermission('G', 'Other', true),
        'B' => SysFileBuilder::make()->groupPermission('G', 'Administrator', true, true),
    ]);

    $rows = collect($result->tab('Group Permissions')->rows())->keyBy(static fn ($row): string => $row->keyValues[1]);

    expect($rows['Administrator']->cellValues)->toBe(['F / T', 'T / T'])
        ->and($rows['Other']->cellValues)->toBe(['T / F', '(absent)']);
});

it('labels group row counts as permission rows including denies', function (): void {
    $result = compareBuilders([
        'A' => SysFileBuilder::make()->group('G')->groupPermission('G', 'P1', false)->groupPermission('G', 'P2', true),
        'B' => SysFileBuilder::make()->group('G'),
    ]);

    $countRow = collect($result->tab('Groups')->rows())->first(static fn ($row): bool => str_starts_with($row->keyValues[1], 'Permission rows'));

    expect($countRow->keyValues[1])->toBe('Permission rows, allow + deny (count)')
        ->and($countRow->cellValues)->toBe(['2', '0']);
});

it('compares exception types as on/off, attention and activation', function (): void {
    $result = compareBuilders([
        'A' => SysFileBuilder::make()->exceptionType('DEFAULT', '90', true, '10', 'Late'),
        'B' => SysFileBuilder::make()->exceptionType('DEFAULT', '90', false, '2'),
    ]);

    $row = $result->tab('Exception Types')->rows()[0];

    expect($row->cellValues)->toBe(['on / attn 10 / act -', 'off / attn 2 / act -'])
        ->and($row->keyValues)->toBe(['DEFAULT', '90', 'Late']);
});

it('does not compare users or any per-user table, and never carries user data', function (): void {
    $result = compareBuilders([
        'A' => SysFileBuilder::make()->user('u1', 'Alice Example')->parameter('DEFAULT', 'P', '1'),
        'B' => SysFileBuilder::make()->user('u2', 'Bob Example')->user('u3', 'Carol Example')->parameter('DEFAULT', 'P', '1'),
    ]);

    $users = collect($result->tableCounts)->firstWhere('table', 'Users');

    expect($users->counts)->toBe([1, 2])
        ->and($users->scope)->toStartWith('Not compared')
        ->and(serialize($result))->not->toContain('Alice Example')
        ->and(serialize($result))->not->toContain('Bob Example')
        ->and($result->totalNotIdentical())->toBe(0);
});

it('survives a table that one environment does not have', function (): void {
    $result = compareBuilders([
        'A' => SysFileBuilder::make()->row('Org_Schedule_Exc_Type_Data', ['profile_id' => 'DEFAULT', 'schedule_exception_type_id' => '1', 'sequence' => '1', 'active' => 'true']),
        'B' => SysFileBuilder::make()->parameter('DEFAULT', 'P', '1'),
    ]);

    $row = collect($result->tab('Profiles & Other')->rows())->firstWhere(static fn ($candidate): bool => $candidate->keyValues[0] === 'Exc type data');

    expect($row->cellValues)->toBe(['active=true', '(absent)']);
});

it('adds parameters with no definition to the template, and marks inferred ones', function (): void {
    $definitions = new ParamDefinitions(
        ['Known' => ['definition' => 'A known parameter.', 'basis' => 'KB: example']],
        [['pattern' => 'Seconds$', 'text' => 'A time setting.', 'basis' => 'Inference (name pattern)']],
    );

    $result = compareBuilders([
        'A' => SysFileBuilder::make()->parameter('DEFAULT', 'Known', '1')->parameter('DEFAULT', 'Mystery', '1')->parameter('DEFAULT', 'RetrySeconds', '1'),
        'B' => SysFileBuilder::make()->parameter('DEFAULT', 'Known', '2')->parameter('DEFAULT', 'Mystery', '2')->parameter('DEFAULT', 'RetrySeconds', '2'),
    ], definitions: $definitions);

    $definitionsByParameter = collect($result->tab('Parameters')->rows())->mapWithKeys(static fn ($row): array => [$row->keyValues[1] => $row->extra['Definition']]);

    expect($definitionsByParameter['Known'])->toBe('A known parameter.')
        ->and($definitionsByParameter['Mystery'])->toBe('')
        ->and($definitionsByParameter['RetrySeconds'])->toBe('[Inferred] A time setting.')
        ->and($result->definitionTemplate)->toBe(['Mystery' => '', 'RetrySeconds' => 'A time setting.'])
        ->and($result->quickRead->differingParametersWithoutDefinition)->toBe(['Mystery']);
});

it('lets a user row replace the built-in row for the same parameter', function (): void {
    $definitions = (new ParamDefinitions(['Known' => ['definition' => 'Built in.', 'note' => 'Built-in note.', 'basis' => 'KB: example']]))->withUserDefinitions([
        ['Parameter' => 'known', 'Definition' => 'Mine.', 'Note' => '', 'Basis' => ''],
        ['Parameter' => 'Mystery', 'Definition' => 'Also mine.', 'Note' => '', 'Basis' => 'Inference (my guess)'],
        ['Parameter' => 'OnlyANote', 'Definition' => '', 'Note' => 'A note.', 'Basis' => ''],
        ['Parameter' => 'Blank', 'Definition' => '  ', 'Note' => '', 'Basis' => ''],
    ]);

    expect($definitions->find('Known')->text)->toBe('Mine.')
        ->and($definitions->find('Known')->basis)->toBe('User-supplied')
        ->and($definitions->find('Mystery')->displayText())->toBe('[Inferred] Also mine.')
        ->and($definitions->find('OnlyANote'))->toBeNull()
        ->and($definitions->find('Blank'))->toBeNull();
});

it('looks definitions up by parameter name regardless of case', function (): void {
    $definitions = ParamDefinitions::builtIn();
    $catalog = ParameterCatalog::builtIn();

    expect($definitions->find('activitytext', '', $catalog)?->text)->toBe($definitions->find('ActivityText', '', $catalog)?->text)
        ->and($definitions->find('ActivityText', '', $catalog))->not->toBeNull()
        ->and($definitions->find('PSWGanttBarColour', '', $catalog))->not->toBeNull();
});

it('rejects an invalid comparison with a plain-English reason', function (array $names, string $baseline, string $message): void {
    $reader = app(SysFileReader::class);
    $environments = array_map(
        static fn (string $name): SysEnvironment => new SysEnvironment($name, $reader->read(SysFileBuilder::make()->parameter('DEFAULT', 'A', '1')->write('x'))),
        $names,
    );

    expect(fn () => app(Comparer::class)->compare($environments, $baseline))->toThrow(InvalidComparison::class, $message);
})->with([
    'one file' => [['A'], 'A', 'At least two'],
    'duplicate names' => [['A', 'A'], 'A', 'more than once'],
    'empty name' => [['A', ' '], 'A', 'needs a name'],
    'unknown baseline' => [['A', 'B'], 'C', 'not one of the environments'],
]);
