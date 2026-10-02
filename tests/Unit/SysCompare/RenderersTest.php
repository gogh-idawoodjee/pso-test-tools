<?php

use App\Support\SysCompare\Comparer;
use App\Support\SysCompare\ComparisonResult;
use App\Support\SysCompare\Render\CsvBundle;
use App\Support\SysCompare\Render\CsvWriter;
use App\Support\SysCompare\Render\DefinitionsTemplate;
use App\Support\SysCompare\Render\HtmlReport;
use App\Support\SysCompare\Render\XlsxWorkbook;
use App\Support\SysCompare\SysEnvironment;
use App\Support\SysCompare\SysFileReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\SysFileBuilder;

const RENDER_FAKE_KEY = 'sk_live_FAKE_RENDER_KEY_0123456789';

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/syscompare-*')) ?: [] as $file) {
        @unlink($file);
    }
});

function renderFixtureResult(): ComparisonResult
{
    $prod = SysFileBuilder::make()
        ->parameter('DEFAULT', 'Tricky', '<script>alert(1)</script> {a|b} https://example.test/?x=1&y=2')
        ->parameter('DEFAULT', 'Formula', '=1+1')
        ->parameter('DEFAULT', 'Same', 'x')
        ->parameter('DEFAULT', 'OnlyInProd', 'yes')
        ->parameter('DEFAULT', 'RoutingApiKey', RENDER_FAKE_KEY)
        ->parameter('DEFAULT', 'OpenIdAuthority', 'https://auth.example.test/realms/prod-realm/')
        ->group('ST_Ops_Mgr')->groupPermission('ST_Ops_Mgr', 'ViewAll', false, true)
        ->exceptionType('DEFAULT', '90', true)
        ->user('u1', 'Alice Example')
        ->version('6.16.0', 'Upgrade from 6.15.0', '2026-05-03T21:47:53+00:00', 'ifs')
        ->version('6.16.0.41', 'Update data', '2026-05-03T21:47:57+00:00', 'ifs');

    $tst = SysFileBuilder::make()
        ->parameter('DEFAULT', 'Tricky', 'plain')
        ->parameter('DEFAULT', 'Formula', '@SUM(1)')
        ->parameter('DEFAULT', 'Same', 'x')
        ->parameter('DEFAULT', 'RoutingApiKey', RENDER_FAKE_KEY)
        ->parameter('DEFAULT', 'OpenIdAuthority', 'https://auth.example.test/realms/tst-realm')
        ->group('ST_Ops_MGR')->groupPermission('ST_Ops_MGR', 'ViewAll', true, true)
        ->exceptionType('DEFAULT', '90', false)
        ->user('u2', 'Bob Example')
        ->version('6.16.0', 'Upgrade from 6.15.0', '2025-10-17T00:31:52+00:00', 'ifs')
        ->version('6.16.0.33', 'Update data', '2025-10-17T00:31:55+00:00', 'ifs');

    $reader = app(SysFileReader::class);

    return app(Comparer::class)->compare([
        new SysEnvironment('PROD', $reader->read($prod->write('prod'), 'prod.xml')),
        new SysEnvironment('TST', $reader->read($tst->write('tst'), 'tst.xml')),
    ], 'PROD', now: new DateTimeImmutable('2026-09-30 12:00:00', new DateTimeZone('UTC')));
}

function renderedHtml(bool $showSameRows = false): string
{
    return app(HtmlReport::class)->render(renderFixtureResult(), $showSameRows);
}

it('renders HTML that parses and escapes every value', function (): void {
    $html = renderedHtml();

    $document = new DOMDocument;
    $loaded = @$document->loadHTML($html);

    expect($loaded)->toBeTrue()
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('{a|b}')
        ->and($html)->toContain('https://example.test/?x=1&amp;y=2');
});

it('renders HTML with no external references', function (): void {
    $html = renderedHtml();

    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@src or @href or @action or @srcset or @data]')->length)->toBe(0)
        ->and($xpath->query('//link | //img | //iframe | //object | //embed | //form')->length)->toBe(0)
        ->and($xpath->query('//script[@src]')->length)->toBe(0)
        ->and($xpath->query('//script')->length)->toBe(1)
        ->and($html)->not->toContain('@import')
        ->and($html)->not->toMatch('/url\s*\(/i');
});

it('shows the red version banner and names what is behind', function (): void {
    $html = renderedHtml();

    expect($html)->toContain('vbanner bad')
        ->and($html)->toContain('NOT ALL ENVIRONMENTS ARE ON THE SAME PSO VERSION')
        ->and($html)->toContain('TST is on 6.16.0.33')
        ->and($html)->toContain('Same release (6.16), different patch builds.')
        ->and($html)->toContain('class="vbad"');
});

it('shows the baseline, the sort hint, amber differences and muted markers', function (): void {
    $html = renderedHtml();

    expect($html)->toContain('<th>PROD (baseline)</th>')
        ->and($html)->toContain('<th>TST</th>')
        ->and($html)->toContain('Click a column header to sort')
        ->and($html)->toContain('<td class="diff">plain</td>')
        ->and($html)->toContain('<td class="muted diff">(absent)</td>')
        ->and($html)->toContain('prod-realm')
        ->and($html)->toContain('tst-realm');
});

it('makes the detail tables sortable but not the tally table', function (): void {
    $html = renderedHtml();

    expect(substr_count($html, 'class="sortable"'))->toBeGreaterThanOrEqual(8)
        ->and($html)->toContain('<table class="tally">')
        ->and($html)->not->toContain('<table class="tally sortable">');
});

it('shows only the differing rows unless asked for all of them', function (): void {
    $differingOnly = renderedHtml();
    $everything = renderedHtml(showSameRows: true);

    expect($differingOnly)->not->toContain('>Same</td>')
        ->and(substr_count($everything, '<td class="k">Same</td>'))->toBe(1)
        ->and(substr_count($differingOnly, '<td class="k">Same</td>'))->toBe(0);
});

it('writes CSVs with a BOM, every field quoted and every row included', function (): void {
    $files = app(CsvBundle::class)->files(renderFixtureResult());

    expect($files)->toHaveKeys(['01_Parameters.csv', '02_ExceptionTypes.csv', '03_GroupPermissions.csv', '04_Groups.csv', '09_TableCounts.csv', '10_Versions.csv', '11_VersionHistory.csv', 'Summary_Tally.csv']);

    foreach ($files as $name => $content) {
        expect(str_starts_with($content, CsvWriter::BOM))->toBeTrue("{$name} has a BOM");
    }

    $parameters = $files['01_Parameters.csv'];

    expect($parameters)->toContain('"Profile","Parameter","AppType","PROD","TST","Status","Default","Definition"')
        ->and($parameters)->toContain('"DEFAULT","Same","ALL","x","x","Same"')
        ->and($parameters)->toContain('"DEFAULT","OnlyInProd","ALL","yes","(absent)","DIFF"');
});

it('quotes embedded quotes and uses CRLF line endings', function (): void {
    $csv = CsvWriter::document(['A', 'B'], [['say "hi"', 'line']]);

    expect($csv)->toBe(CsvWriter::BOM."\"A\",\"B\"\r\n\"say \"\"hi\"\"\",\"line\"\r\n");
});

it('zips the CSV bundle', function (): void {
    $path = storage_path('framework/testing/syscompare-bundle.zip');
    app(CsvBundle::class)->write(renderFixtureResult(), $path);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $names = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = $zip->getNameIndex($index);
    }

    $zip->close();

    expect($names)->toContain('01_Parameters.csv')->toContain('Summary_Tally.csv')->toContain('11_VersionHistory.csv');
});

it('lists parameters with no definition in the template', function (): void {
    $csv = DefinitionsTemplate::csv(renderFixtureResult());

    expect($csv)->toContain('"Parameter","Definition","Basis","CurrentHint"')
        ->and($csv)->toContain('"Tricky"')
        ->and($csv)->toContain('"OnlyInProd"');
});

it('writes an Excel workbook with the expected sheets, in order', function (): void {
    $path = storage_path('framework/testing/syscompare-book.xlsx');
    app(XlsxWorkbook::class)->write(renderFixtureResult(), $path);

    $reader = new XlsxReader;
    $reader->open($path);

    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $sheets[] = $sheet->getName();
    }

    $reader->close();

    expect($sheets)->toBe(['Summary', 'Versions', 'Parameters', 'Exception Types', 'Group Permissions', 'Groups', 'Org Permissions', 'Lists', 'Travel', 'Profiles & Other', 'Table Counts', 'Version History']);
});

it('writes no formulas: text starting with = or @ stays text', function (): void {
    $path = storage_path('framework/testing/syscompare-formula.xlsx');
    app(XlsxWorkbook::class)->write(renderFixtureResult(), $path);

    $zip = new ZipArchive;
    $zip->open($path);

    $worksheets = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (str_starts_with($name, 'xl/worksheets/sheet')) {
            $worksheets[$name] = (string) $zip->getFromIndex($index);
        }
    }

    $zip->close();

    expect($worksheets)->toHaveCount(12)
        ->and(implode('', $worksheets))->toContain('=1+1')->toContain('@SUM(1)');

    foreach ($worksheets as $name => $xml) {
        expect($xml)->not->toContain('<f>', "{$name} contains a formula");
    }
});

it('never lets the API key reach any generated artifact', function (): void {
    $result = renderFixtureResult();
    $directory = storage_path('framework/testing');

    $xlsx = $directory.'/syscompare-leak.xlsx';
    $zip = $directory.'/syscompare-leak.zip';
    app(XlsxWorkbook::class)->write($result, $xlsx);
    app(CsvBundle::class)->write($result, $zip);

    $artifacts = [
        'html' => renderedHtml(showSameRows: true),
        'template' => DefinitionsTemplate::csv($result) ?? '',
        'serialized result' => serialize($result),
    ];

    foreach (app(CsvBundle::class)->files($result) as $name => $content) {
        $artifacts[$name] = $content;
    }

    foreach ([$xlsx, $zip] as $archive) {
        $opened = new ZipArchive;
        $opened->open($archive);

        for ($index = 0; $index < $opened->numFiles; $index++) {
            $artifacts[basename($archive).'/'.$opened->getNameIndex($index)] = (string) $opened->getFromIndex($index);
        }

        $opened->close();
    }

    foreach ($artifacts as $name => $content) {
        expect($content)->not->toContain(RENDER_FAKE_KEY, "{$name} leaked the API key");
        expect($content)->not->toContain('Alice Example', "{$name} leaked user data");
    }

    expect($artifacts['html'])->toContain('[API key set]');
});
