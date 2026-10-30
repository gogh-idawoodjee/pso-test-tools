<?php

use App\Support\ScheduleData\BroadcastInjection;
use App\Support\ScheduleData\BroadcastSettings;
use App\Support\ScheduleData\InputReferenceIdentity;
use App\Support\ScheduleData\JsonScheduleDataRewriter;
use App\Support\ScheduleData\MissingScheduleDataWrapperException;
use App\Support\ScheduleData\RewriteReport;
use App\Support\ScheduleData\ScheduleDataRewriterFactory;
use App\Support\ScheduleData\XmlScheduleDataRewriter;

const XML_NS = 'http://360Scheduling.com/Schema/dsScheduleData.xsd';

/**
 * @return array{0: string, 1: RewriteReport}
 */
function rewriteXml(string $xml, ?string $datasetIdOverride = null, ?BroadcastInjection $injection = null, bool $stripExisting = true, ?InputReferenceIdentity $identity = null): array
{
    $source = tempnam(sys_get_temp_dir(), 'xmlsrc');
    $destination = tempnam(sys_get_temp_dir(), 'xmldst');
    file_put_contents($source, $xml);

    try {
        $report = app(XmlScheduleDataRewriter::class)->rewrite($source, $destination, $datasetIdOverride, $injection, $stripExisting, $identity);

        return [file_get_contents($destination), $report];
    } finally {
        @unlink($source);
        @unlink($destination);
    }
}

function xmlWebhookInjection(): BroadcastInjection
{
    return new BroadcastInjection(BroadcastSettings::shipped(), 'https://webhook.example.test/pso-env');
}

function xpathFor(string $xml): DOMXPath
{
    $document = new DOMDocument;
    expect($document->loadXML($xml))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', XML_NS);

    return $xpath;
}

it('injects a Broadcast and its parameters in the root namespace', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Resources><id>R1</id></Resources></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, null, xmlWebhookInjection());

    $xpath = xpathFor($output);

    expect($report->format)->toBe('xml');
    expect($report->wrapperFound)->toBeTrue();
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Resources/s:id)'))->toBe('R1');
    expect($xpath->evaluate('count(/s:dsScheduleData/s:Broadcast)'))->toBe(1.0);
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Broadcast/s:id)'))->toBe('WEBHOOK_COMPLETE');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Broadcast/s:maximum_wait)'))->toBe('PT1M');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Broadcast/s:once_only)'))->toBe('false');
    expect($xpath->evaluate('count(/s:dsScheduleData/s:Broadcast_Parameter)'))->toBe(5.0);
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Broadcast_Parameter[s:parameter_name="url"]/s:parameter_value)'))
        ->toBe('https://webhook.example.test/pso-env');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Broadcast_Parameter[s:parameter_name="mediatype"]/s:parameter_value)'))
        ->toBe('application/xml');
});

it('writes the Broadcast fields in the script order', function () {
    [$output] = rewriteXml('<dsScheduleData xmlns="'.XML_NS.'"/>', null, xmlWebhookInjection());

    $names = [];

    foreach (xpathFor($output)->query('/s:dsScheduleData/s:Broadcast/*') as $node) {
        $names[] = $node->localName;
    }

    expect($names)->toBe([
        'id', 'active', 'broadcast_type_id', 'plan_type', 'minimum_plan_quality',
        'minimum_step_interval', 'once_only', 'maximum_wait', 'maximum_frequency', 'allocation_type',
    ]);
});

it('strips existing broadcast rows and reports the counts', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'">'
        .'<Broadcast><id>OLD1</id></Broadcast><Broadcast><id>OLD2</id></Broadcast>'
        .'<Resources><id>R1</id></Resources>'
        .'<Broadcast_Parameter><broadcast_id>OLD1</broadcast_id></Broadcast_Parameter>'
        .'</dsScheduleData>';

    [$output, $report] = rewriteXml($xml, null, xmlWebhookInjection());

    expect($report->strippedBroadcasts)->toBe(2);
    expect($report->strippedBroadcastParameters)->toBe(1);
    expect($output)->not->toContain('OLD1');
    expect(xpathFor($output)->evaluate('count(/s:dsScheduleData/s:Broadcast)'))->toBe(1.0);
});

it('does not strip a Broadcast element nested below the root children', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Resources><Broadcast>DEEP</Broadcast></Resources></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, null, xmlWebhookInjection());

    expect($report->strippedBroadcasts)->toBe(0);
    expect(xpathFor($output)->evaluate('string(/s:dsScheduleData/s:Resources/s:Broadcast)'))->toBe('DEEP');
});

it('keeps existing Broadcast and Broadcast_Parameter elements byte for byte when stripping is off and nothing is injected', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS."\">\n  <Input_Reference><dataset_id>OLD</dataset_id></Input_Reference>\n"
        ."  <Broadcast ><id>MINE</id><active>true</active></Broadcast>\n"
        ."  <Broadcast_Parameter><broadcast_id>MINE</broadcast_id><parameter_value>https://x/y?a=1&amp;b=2</parameter_value></Broadcast_Parameter>\n"
        .'</dsScheduleData>';

    [$output, $report] = rewriteXml($xml, 'NEW', null, stripExisting: false);

    expect($output)->toBe(str_replace('>OLD<', '>NEW<', $xml));
    expect($report->strippedBroadcasts)->toBe(0);
    expect($report->strippedBroadcastParameters)->toBe(0);
    expect($report->datasetIdChanged)->toBeTrue();
});

it('replaces dataset_id inside Input_Reference only', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'">'
        .'<Input_Reference><datetime>2026-10-11T00:00:00Z</datetime><dataset_id>OLD</dataset_id></Input_Reference>'
        .'<Resources><dataset_id>KEEP</dataset_id></Resources>'
        .'</dsScheduleData>';

    [$output, $report] = rewriteXml($xml, 'NEW');

    $xpath = xpathFor($output);

    expect($xpath->evaluate('string(/s:dsScheduleData/s:Input_Reference/s:dataset_id)'))->toBe('NEW');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Input_Reference/s:datetime)'))->toBe('2026-10-11T00:00:00Z');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Resources/s:dataset_id)'))->toBe('KEEP');
    expect($report->datasetIds)->toBe(['OLD']);
    expect($report->datasetIdChanged)->toBeTrue();
});

it('reports the existing dataset ids decoded, without an override, and changes nothing', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'">'
        .'<Input_Reference><dataset_id>A&amp;B</dataset_id></Input_Reference>'
        .'<Input_Reference><dataset_id><![CDATA[C<D]]></dataset_id></Input_Reference>'
        .'<Input_Reference><dataset_id>A&amp;B</dataset_id></Input_Reference>'
        .'</dsScheduleData>';

    [$output, $report] = rewriteXml($xml);

    expect($output)->toBe($xml);
    expect($report->datasetIds)->toBe(['A&B', 'C<D']);
    expect($report->datasetIdChanged)->toBeFalse();
});

it('reports no change when the override equals the existing value', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id>SAME</dataset_id></Input_Reference></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, 'SAME');

    expect($output)->toBe($xml);
    expect($report->datasetIdChanged)->toBeFalse();
});

it('escapes an override containing XML special characters', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id>OLD</dataset_id></Input_Reference></dsScheduleData>';

    [$output] = rewriteXml($xml, 'A&B <C> "D"');

    expect(xpathFor($output)->evaluate('string(/s:dsScheduleData/s:Input_Reference/s:dataset_id)'))->toBe('A&B <C> "D"');
});

it('writes a non-ASCII override so it survives any declared encoding', function () {
    $xml = '<?xml version="1.0" encoding="ISO-8859-1"?><dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id>OLD</dataset_id></Input_Reference></dsScheduleData>';

    [$output] = rewriteXml($xml, 'Région Nord');

    expect(xpathFor($output)->evaluate('string(/s:dsScheduleData/s:Input_Reference/s:dataset_id)'))->toBe('Région Nord');
});

it('refuses an override with characters XML cannot hold', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id>OLD</dataset_id></Input_Reference></dsScheduleData>';

    expect(static fn () => rewriteXml($xml, "BAD\x01"))->toThrow(RuntimeException::class, 'cannot hold');
});

it('replaces an empty dataset_id element', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id/></Input_Reference></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, 'NEW');

    expect(xpathFor($output)->evaluate('string(/s:dsScheduleData/s:Input_Reference/s:dataset_id)'))->toBe('NEW');
    expect($report->datasetIds)->toBe(['']);
    expect($report->datasetIdChanged)->toBeTrue();
});

it('keeps other content, attributes, comments, cdata and escaped text', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        .'<dsScheduleData xmlns="'.XML_NS.'" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        .'<!-- kept --><Activity id="A&amp;1" xsi:nil="false"><description><![CDATA[x < y]]></description>'
        .'<notes>fish &amp; chips</notes></Activity></dsScheduleData>';

    [$output] = rewriteXml($xml, null, xmlWebhookInjection());

    $xpath = xpathFor($output);

    expect($xpath->evaluate('string(/s:dsScheduleData/s:Activity/@id)'))->toBe('A&1');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Activity/s:description)'))->toBe('x < y');
    expect($xpath->evaluate('string(/s:dsScheduleData/s:Activity/s:notes)'))->toBe('fish & chips');
    expect($output)->toContain('<!-- kept -->');
});

it('keeps every untouched byte of a pretty-printed CRLF file verbatim', function () {
    $prefix = "\xEF\xBB\xBF<?xml version='1.0' encoding='utf-8' standalone='yes'?>\r\n"
        ."<!-- export -->\r\n"
        ."<dsScheduleData xmlns='".XML_NS."' >\r\n"
        ."\t<Activity id='a>b' note=\"it's\"><description>&#x41;&lt;&gt;&quot;</description><empty></empty><self /></Activity>\r\n";
    $stripped = "\t<Broadcast><id>OLD</id></Broadcast>\r\n";
    $suffix = "</dsScheduleData>\r\n";

    [$output] = rewriteXml($prefix.$stripped.$suffix, null, xmlWebhookInjection());

    expect($output)->toStartWith($prefix."\t<Broadcast>");
    expect($output)->toEndWith("</Broadcast_Parameter>\r\n".$suffix);
    expect($output)->not->toContain('OLD');
    expect(substr_count($output, "\r\n\t<Broadcast_Parameter>"))->toBe(5);
    expect(xpathFor($output)->evaluate('string(/s:dsScheduleData/s:Activity/@id)'))->toBe('a>b');
});

it('injects into an empty root element', function () {
    [$output] = rewriteXml('<dsScheduleData xmlns="'.XML_NS.'"/>', null, xmlWebhookInjection());

    expect(xpathFor($output)->evaluate('count(/s:dsScheduleData/s:Broadcast)'))->toBe(1.0);
});

it('writes injected elements with the root prefix when the root is prefixed', function () {
    $xml = '<ds:dsScheduleData xmlns:ds="'.XML_NS.'"><ds:Resources><ds:id>R1</ds:id></ds:Resources></ds:dsScheduleData>';

    [$output] = rewriteXml($xml, null, xmlWebhookInjection());

    expect(xpathFor($output)->evaluate('count(/s:dsScheduleData/s:Broadcast)'))->toBe(1.0);
    expect($output)->toContain('<ds:Broadcast><ds:id>WEBHOOK_COMPLETE</ds:id>');
});

it('accepts a ScheduleData root', function () {
    [$output] = rewriteXml('<ScheduleData xmlns="'.XML_NS.'"></ScheduleData>', null, xmlWebhookInjection());

    expect(xpathFor($output)->evaluate('count(/s:ScheduleData/s:Broadcast_Parameter)'))->toBe(5.0);
});

it('fails when the root element is not schedule data', function () {
    expect(static fn () => rewriteXml('<other xmlns="x"><a/></other>', null, xmlWebhookInjection()))
        ->toThrow(MissingScheduleDataWrapperException::class, "Expected a top-level 'ScheduleData' or 'dsScheduleData' wrapper");
});

it('copies a file without a schedule data root unchanged when nothing is injected', function () {
    $xml = '<other><Input_Reference><dataset_id>OLD</dataset_id></Input_Reference></other>';

    [$output, $report] = rewriteXml($xml, 'NEW');

    expect($output)->toBe($xml);
    expect($report->format)->toBeNull();
    expect($report->wrapperFound)->toBeFalse();
    expect($report->datasetIds)->toBe([]);
    expect($report->datasetIdChanged)->toBeFalse();
    expect($report->bytesWritten)->toBe(strlen($xml));
});

it('copies a file with no root element at all unchanged when nothing is injected', function () {
    [$output, $report] = rewriteXml("not xml at all\n", 'NEW');

    expect($output)->toBe("not xml at all\n");
    expect($report->bytesWritten)->toBe(15);
});

it('fails when the file ends inside the root element', function () {
    expect(static fn () => rewriteXml('<dsScheduleData xmlns="'.XML_NS.'"><Resources><id>R1</id>', null, xmlWebhookInjection()))
        ->toThrow(RuntimeException::class, 'truncated');
    expect(static fn () => rewriteXml('<dsScheduleData xmlns="'.XML_NS.'"><Resources><id', 'NEW'))
        ->toThrow(RuntimeException::class, 'truncated');
});

it('never modifies the source file', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Broadcast><id>OLD</id></Broadcast><Input_Reference><dataset_id>OLD</dataset_id></Input_Reference></dsScheduleData>';
    $source = tempnam(sys_get_temp_dir(), 'xmlsrc');
    $destination = tempnam(sys_get_temp_dir(), 'xmldst');
    file_put_contents($source, $xml);

    try {
        app(XmlScheduleDataRewriter::class)->rewrite($source, $destination, 'NEW', xmlWebhookInjection());

        expect(file_get_contents($source))->toBe($xml);
    } finally {
        @unlink($source);
        @unlink($destination);
    }
});

it('refuses to write over the source file', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id>OLD</dataset_id></Input_Reference></dsScheduleData>';
    $source = tempnam(sys_get_temp_dir(), 'xmlsrc');
    file_put_contents($source, $xml);

    try {
        expect(static fn () => app(XmlScheduleDataRewriter::class)->rewrite($source, $source, 'NEW', xmlWebhookInjection()))
            ->toThrow(RuntimeException::class, 'cannot be written over the original');
        expect(file_get_contents($source))->toBe($xml);
    } finally {
        @unlink($source);
    }
});

it('edits the same way wherever the read chunk boundary falls', function () {
    $head = '<dsScheduleData xmlns="'.XML_NS."\">\n  ";
    $tail = "\n  <Broadcast><id>OLD</id></Broadcast>"
        ."\n  <Input_Reference><dataset_id>OLD</dataset_id></Input_Reference>"
        ."\n  <Broadcast_Parameter/>\n<!---->\n</dsScheduleData>\n";

    [$smallOutput] = rewriteXml($head.'<Pad/>'.$tail, 'NEW', xmlWebhookInjection());
    expect($smallOutput)->toStartWith($head.'<Pad/>');
    $expectedTail = substr($smallOutput, strlen($head.'<Pad/>'));

    $chunk = 1048576;
    $fixed = strlen($head.'<Pad></Pad>');

    for ($shift = -260; $shift <= 20; $shift++) {
        $padded = $head.'<Pad>'.str_repeat('p', $chunk - $fixed + $shift).'</Pad>';

        [$output, $report] = rewriteXml($padded.$tail, 'NEW', xmlWebhookInjection());

        expect($output)->toBe($padded.$expectedTail);
        expect($report->strippedBroadcasts)->toBe(1);
        expect($report->strippedBroadcastParameters)->toBe(1);
        expect($report->datasetIds)->toBe(['OLD']);
    }
});

it('rewrites a large xml file in constant memory', function () {
    $source = tempnam(sys_get_temp_dir(), 'bigxmlsrc');
    $destination = tempnam(sys_get_temp_dir(), 'bigxmldst');

    $handle = fopen($source, 'wb');
    fwrite($handle, '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id>OLD</dataset_id></Input_Reference>');
    $row = '<Activity><id>ACT-000000</id><description>'.str_repeat('x', 200).'</description><priority>5</priority></Activity>';

    for ($index = 0; $index < 100000; $index++) {
        fwrite($handle, $row);
    }

    fwrite($handle, '</dsScheduleData>');
    fclose($handle);

    memory_reset_peak_usage();
    $before = memory_get_usage();

    try {
        $report = app(XmlScheduleDataRewriter::class)->rewrite($source, $destination, 'NEW', xmlWebhookInjection());
        $growth = memory_get_peak_usage() - $before;

        expect(filesize($source))->toBeGreaterThan(20 * 1024 * 1024);
        expect($report->bytesWritten)->toBe(filesize($destination));
        expect($report->datasetIds)->toBe(['OLD']);
        expect($growth)->toBeLessThan(16 * 1024 * 1024);
    } finally {
        @unlink($source);
        @unlink($destination);
    }
});

it('picks the rewriter by format', function () {
    $factory = app(ScheduleDataRewriterFactory::class);

    expect($factory->forFormat('xml'))->toBeInstanceOf(XmlScheduleDataRewriter::class);
    expect($factory->forFormat('json'))->toBeInstanceOf(JsonScheduleDataRewriter::class);
});

it('reports the same wrapperless shape as the JSON rewriter', function () {
    [, $xmlReport] = rewriteXml('<other><a/></other>', 'NEW');
    $source = tempnam(sys_get_temp_dir(), 'rwsrc');
    $destination = tempnam(sys_get_temp_dir(), 'rwdst');
    file_put_contents($source, '{"Other":{"a":1}}');

    try {
        $jsonReport = app(JsonScheduleDataRewriter::class)->rewrite($source, $destination, 'NEW', null);
    } finally {
        @unlink($source);
        @unlink($destination);
    }

    expect([$xmlReport->format, $xmlReport->wrapperFound])->toBe([null, false]);
    expect([$jsonReport->format, $jsonReport->wrapperFound])->toBe([null, false]);
});

it('strips existing Broadcast elements without injecting when asked, leaving every other byte as it was', function () {
    $prefix = "<?xml version='1.0' encoding='utf-8'?>\r\n<dsScheduleData xmlns='".XML_NS."'>\r\n"
        ."\t<Input_Reference><dataset_id>OLD</dataset_id></Input_Reference>";
    $middle = "\r\n\t<Resources><id>R1</id></Resources>";
    $suffix = "\r\n</dsScheduleData>\r\n";
    $xml = $prefix."\r\n\t<Broadcast><id>MINE</id></Broadcast>".$middle
        ."\r\n\t<Broadcast_Parameter><broadcast_id>MINE</broadcast_id></Broadcast_Parameter>"
        ."\r\n\t<Broadcast_Parameter/>".$suffix;

    [$output, $report] = rewriteXml($xml);

    expect($output)->toBe($prefix.$middle.$suffix);
    expect($report->strippedBroadcasts)->toBe(1);
    expect($report->strippedBroadcastParameters)->toBe(2);
    expect($report->datasetIdChanged)->toBeFalse();
});

it('strips existing Broadcast elements when injecting even if stripping is off', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Broadcast><id>OLD1</id></Broadcast>'
        .'<Broadcast_Parameter><broadcast_id>OLD1</broadcast_id></Broadcast_Parameter></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, null, xmlWebhookInjection(), stripExisting: false);

    expect($report->strippedBroadcasts)->toBe(1);
    expect($report->strippedBroadcastParameters)->toBe(1);
    expect($output)->not->toContain('OLD1');
    expect(xpathFor($output)->evaluate('count(/s:dsScheduleData/s:Broadcast)'))->toBe(1.0);
});

it('copies a file without a schedule data root unchanged when only stripping is asked for', function () {
    $xml = '<other><Broadcast><id>OLD</id></Broadcast></other>';

    [$output, $report] = rewriteXml($xml);

    expect($output)->toBe($xml);
    expect($report->wrapperFound)->toBeFalse();
    expect($report->strippedBroadcasts)->toBe(0);
});

function xmlIdentity(): InputReferenceIdentity
{
    return new InputReferenceIdentity('PSO Test Tools', 'Sent from PSO Test Tools (Load from File) by Ré <Q> & co, environment TST, file load.xml, 2026-10-16 13:40Z');
}

const XML_IDENTITY_DESCRIPTION = 'Sent from PSO Test Tools (Load from File) by R&#xE9; &lt;Q&gt; &amp; co, environment TST, file load.xml, 2026-10-16 13:40Z';

it('replaces existing user_id and description text, leaving every other byte as it was', function () {
    $xml = "<?xml version=\"1.0\"?>\r\n<dsScheduleData xmlns=\"".XML_NS."\">\r\n"
        ."\t<Input_Reference>\r\n\t\t<id>7</id>\r\n\t\t<user_id>IFS</user_id>\r\n\t\t<dataset_id>N</dataset_id>\r\n\t\t<description/>\r\n\t</Input_Reference>\r\n"
        ."\t<Activity><id>A1</id><description>keep me</description><user_id>x</user_id></Activity>\r\n</dsScheduleData>\r\n";

    [$output, $report] = rewriteXml($xml, identity: xmlIdentity());

    expect($output)->toBe(str_replace(
        ['<user_id>IFS</user_id>', '<description/>'],
        ['<user_id>PSO Test Tools</user_id>', '<description>'.XML_IDENTITY_DESCRIPTION.'</description>'],
        $xml,
    ));
    expect(xpathFor($output)->evaluate('string(/s:dsScheduleData/s:Input_Reference/s:description)'))->toBe(xmlIdentity()->description);
    expect($report->inputReferencesMarked)->toBe(1);
    expect($report->identityFieldsReplaced)->toBe(2);
});

it('adds missing user_id and description before the closing Input_Reference tag in the indentation of its children', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS."\">\n  <Input_Reference>\n    <id>7</id>\n    <dataset_id>N</dataset_id>\n  </Input_Reference>\n  <Resources><id>R1</id></Resources>\n</dsScheduleData>\n";

    [$output, $report] = rewriteXml($xml, identity: xmlIdentity());

    expect($output)->toBe(str_replace(
        "<dataset_id>N</dataset_id>\n  </Input_Reference>",
        "<dataset_id>N</dataset_id>\n    <user_id>PSO Test Tools</user_id>\n    <description>".XML_IDENTITY_DESCRIPTION."</description>\n  </Input_Reference>",
        $xml,
    ));
    expect($report->inputReferencesMarked)->toBe(1);
    expect($report->identityFieldsReplaced)->toBe(0);
});

it('writes added identity elements with the prefix of a prefixed Input_Reference', function () {
    $xml = '<p:dsScheduleData xmlns:p="'.XML_NS.'"><p:Input_Reference><p:dataset_id>N</p:dataset_id><p:user_id>me</p:user_id></p:Input_Reference></p:dsScheduleData>';

    [$output] = rewriteXml($xml, identity: xmlIdentity());

    expect($output)->toBe('<p:dsScheduleData xmlns:p="'.XML_NS.'"><p:Input_Reference><p:dataset_id>N</p:dataset_id><p:user_id>PSO Test Tools</p:user_id>'
        .'<p:description>'.XML_IDENTITY_DESCRIPTION.'</p:description></p:Input_Reference></p:dsScheduleData>');
    expect(xpathFor($output)->evaluate('count(/s:dsScheduleData/s:Input_Reference/s:description)'))->toBe(1.0);
});

it('marks every root-level Input_Reference, an empty one included, and nothing nested', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><dataset_id>A</dataset_id></Input_Reference>'
        .'<Input_Reference/>'
        .'<Activity><Input_Reference><id>nested</id></Input_Reference></Activity>'
        .'<Input_Reference><description>old</description><dataset_id>B</dataset_id></Input_Reference></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, 'NEW', identity: xmlIdentity());

    $xpath = xpathFor($output);

    expect($xpath->evaluate('count(/s:dsScheduleData/s:Input_Reference[s:user_id = "PSO Test Tools"])'))->toBe(3.0);
    expect($xpath->evaluate('count(/s:dsScheduleData/s:Input_Reference[s:description = "'.xmlIdentity()->description.'"])'))->toBe(3.0);
    expect($xpath->evaluate('count(//s:Activity//s:user_id)'))->toBe(0.0);
    expect($output)->toContain('<Activity><Input_Reference><id>nested</id></Input_Reference></Activity>');
    expect($report->inputReferencesMarked)->toBe(3);
    expect($report->identityFieldsReplaced)->toBe(1);
    expect($report->datasetIds)->toBe(['A', 'B']);
    expect($xpath->evaluate('count(/s:dsScheduleData/s:Input_Reference[s:dataset_id = "NEW"])'))->toBe(2.0);
});

it('marks Input_Reference the same way wherever the read chunk boundary falls', function () {
    $head = '<dsScheduleData xmlns="'.XML_NS."\">\n  ";
    $tail = "\n  <Input_Reference>\n    <user_id>IFS</user_id>\n    <dataset_id>OLD</dataset_id>\n  </Input_Reference>\n</dsScheduleData>\n";

    [$smallOutput] = rewriteXml($head.'<Pad/>'.$tail, 'NEW', xmlWebhookInjection(), true, xmlIdentity());
    $expectedTail = substr($smallOutput, strlen($head.'<Pad/>'));

    expect($expectedTail)->toContain("<dataset_id>NEW</dataset_id>\n    <description>");

    $chunk = 1048576;
    $fixed = strlen($head.'<Pad></Pad>');

    for ($shift = -150; $shift <= 10; $shift++) {
        $padded = $head.'<Pad>'.str_repeat('p', $chunk - $fixed + $shift).'</Pad>';

        [$output, $report] = rewriteXml($padded.$tail, 'NEW', xmlWebhookInjection(), true, xmlIdentity());

        expect($output)->toBe($padded.$expectedTail);
        expect($report->inputReferencesMarked)->toBe(1);
        expect($report->identityFieldsReplaced)->toBe(1);
    }
});

it('marks nothing in a file with no Input_Reference', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Resources><id>R1</id><user_id>x</user_id></Resources></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, identity: xmlIdentity());

    expect($output)->toBe($xml);
    expect($report->inputReferencesMarked)->toBe(0);
});

function shortXmlIdentity(string $description = 'D'): InputReferenceIdentity
{
    return new InputReferenceIdentity('PSO Test Tools', $description);
}

it('marks an Input_Reference that holds comments', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><!-- first --><dataset_id>N</dataset_id><!-- last -->'."\n".'</Input_Reference></dsScheduleData>';

    [$output] = rewriteXml($xml, identity: shortXmlIdentity());

    expect($output)->toBe('<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><!-- first --><dataset_id>N</dataset_id>'
        .'<user_id>PSO Test Tools</user_id><description>D</description><!-- last -->'."\n".'</Input_Reference></dsScheduleData>');
});

it('replaces a CDATA description and keeps CDATA in other children', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><id><![CDATA[a</id>]]></id><description><![CDATA[old <x>]]></description></Input_Reference></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, identity: shortXmlIdentity());

    expect($output)->toBe('<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><id><![CDATA[a</id>]]></id><description>D</description>'
        .'<user_id>PSO Test Tools</user_id></Input_Reference></dsScheduleData>');
    expect($report->identityFieldsReplaced)->toBe(1);
});

it('keeps attributes on Input_Reference, in the open and the self-closing form', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference a="1" b=\'x>y\'><dataset_id>N</dataset_id></Input_Reference><Input_Reference a="2" /></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, identity: shortXmlIdentity());

    expect($output)->toBe('<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference a="1" b=\'x>y\'><dataset_id>N</dataset_id><user_id>PSO Test Tools</user_id><description>D</description></Input_Reference>'
        .'<Input_Reference a="2"><user_id>PSO Test Tools</user_id><description>D</description></Input_Reference></dsScheduleData>');
    expect($report->inputReferencesMarked)->toBe(2);
});

it('fills an empty description element written with both tags', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><user_id></user_id><description></description></Input_Reference></dsScheduleData>';

    [$output, $report] = rewriteXml($xml, identity: shortXmlIdentity('a\\b'));

    expect($output)->toBe('<dsScheduleData xmlns="'.XML_NS.'"><Input_Reference><user_id>PSO Test Tools</user_id><description>a\\b</description></Input_Reference></dsScheduleData>');
    expect($report->identityFieldsReplaced)->toBe(2);
});

it('adds both fields to an Input_Reference holding only whitespace', function () {
    $xml = '<dsScheduleData xmlns="'.XML_NS."\">\n  <Input_Reference>\n  </Input_Reference>\n</dsScheduleData>";

    [$output] = rewriteXml($xml, identity: shortXmlIdentity());

    expect($output)->toBe('<dsScheduleData xmlns="'.XML_NS."\">\n  <Input_Reference><user_id>PSO Test Tools</user_id><description>D</description>\n  </Input_Reference>\n</dsScheduleData>");
});
