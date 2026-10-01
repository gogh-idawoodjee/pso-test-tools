<?php

use App\Models\User;
use App\Support\SysCompare\Storage\SysCompareStorage;
use App\Support\SysCompare\SysCompareArtifact;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('sys-compare');
});

function sysCompareDownloadUrl(string $run, string $artifact, array $query = []): string
{
    return route('filament.app.sys-compare.runs.download', ['run' => $run, 'artifact' => $artifact] + $query);
}

function sysCompareRunFor(int $userId): string
{
    $storage = app(SysCompareStorage::class);
    $run = SysCompareStorage::newId();

    $storage->putRunFile($userId, $run, SysCompareArtifact::Report->fileName(), '<html>report</html>');
    $storage->putRunFile($userId, $run, SysCompareArtifact::Csv->fileName(), 'zip-bytes');

    return $run;
}

it('requires a signed-in user', function (): void {
    $run = sysCompareRunFor(101);

    $this->get(sysCompareDownloadUrl($run, 'report'))->assertRedirect();
});

it('serves a run output to its owner as a download', function (): void {
    $this->actingAs(User::factory()->make(['id' => 101, 'email' => 'tester@mac.com']));
    $run = sysCompareRunFor(101);

    $response = $this->get(sysCompareDownloadUrl($run, 'csv'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertHeader('Content-Disposition', 'attachment; filename='.SysCompareArtifact::CSV_FILE_NAME)
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('never serves one user the results of another', function (): void {
    $this->actingAs(User::factory()->make(['id' => 202, 'email' => 'other@mac.com']));
    $run = sysCompareRunFor(101);

    $this->get(sysCompareDownloadUrl($run, 'report'))->assertNotFound();
});

it('only serves the known artifacts', function (string $artifact): void {
    $this->actingAs(User::factory()->make(['id' => 101, 'email' => 'tester@mac.com']));
    $run = sysCompareRunFor(101);

    $this->get(sysCompareDownloadUrl($run, $artifact))->assertNotFound();
})->with(['summary.json', '..%2F..%2F.env', 'unknown', 'template']);

it('serves the report inline in a sandbox when asked, and as a download otherwise', function (): void {
    $this->actingAs(User::factory()->make(['id' => 101, 'email' => 'tester@mac.com']));
    $run = sysCompareRunFor(101);

    $inline = $this->get(sysCompareDownloadUrl($run, 'report', ['inline' => 1]));
    $download = $this->get(sysCompareDownloadUrl($run, 'report'));

    $inline->assertOk();
    expect($inline->headers->get('Content-Security-Policy'))->toContain('sandbox allow-scripts')->toContain("default-src 'none'")
        ->and($inline->headers->get('Content-Disposition'))->toContain('inline')
        ->and($download->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($download->headers->get('Content-Security-Policy'))->toBeNull();
});

it('returns not found for a malformed run id', function (): void {
    $this->actingAs(User::factory()->make(['id' => 101, 'email' => 'tester@mac.com']));

    $this->get(sysCompareDownloadUrl('not-a-valid-run-id', 'report'))->assertNotFound();
});
