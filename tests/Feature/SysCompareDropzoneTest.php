<?php

use Illuminate\Support\Facades\Process;

function renderedDropzoneComponent(string $kind): string
{
    $html = view('filament.pages.sys-file-compare.dropzone', ['kind' => $kind])->render();

    preg_match('/x-data="(.*?)"\n    class=/s', $html, $matches);

    return 'const component = ('.html_entity_decode($matches[1], ENT_QUOTES).");\n";
}

it('lets all eight files in and clears finished uploads from the list, as Alpine runs it', function (): void {
    $node = Process::run(['node', '--version']);

    if (! $node->successful()) {
        $this->markTestSkipped('Node is not installed.');
    }

    $path = storage_path('framework/testing/dropzone-component-'.bin2hex(random_bytes(4)).'.js');
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, renderedDropzoneComponent('sys'));

    try {
        $result = Process::run(['node', base_path('tests/Support/dropzone-harness.cjs'), $path]);
    } finally {
        @unlink($path);
    }

    expect($result->output())->toContain('ALL OK')
        ->and($result->successful())->toBeTrue($result->output().$result->errorOutput());
});

it('renders the drop zone with real buttons for Dismiss and Remove', function (string $kind): void {
    $html = view('filament.pages.sys-file-compare.dropzone', ['kind' => $kind])->render();

    expect($html)->toContain('fi-btn')
        ->and($html)->toContain($kind === 'sys' ? 'Dismiss' : 'Remove')
        // finished uploads are removed by id: comparing objects fails once Alpine proxies them
        ->and($html)->toContain('item.id !== id')
        ->and($html)->not->toContain('item !== upload');
})->with(['sys', 'definitions']);
