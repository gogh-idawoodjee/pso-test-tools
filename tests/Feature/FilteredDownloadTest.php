<?php

use App\Jobs\ProcessResourceFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::disk('public')->put('filtered_download-test.json', '{"ok":true}');
});

afterEach(function () {
    Storage::disk('public')->delete('filtered_download-test.json');
});

it('sends an anonymous download request to the login page', function () {
    $this->get(route('download.filtered', ['filename' => 'filtered_download-test.json']))
        ->assertRedirect();
});

it('lets a signed-in user download an existing file', function () {
    $this->actingAs(User::factory()->create(['email' => 'ish@mac.com']))
        ->get(route('download.filtered', ['filename' => 'filtered_download-test.json']))
        ->assertOk()
        ->assertDownload('filtered_download-test.json');
});

it('returns 404 for names that try to leave the public folder', function (string $uri) {
    $this->actingAs(User::factory()->create(['email' => 'ish@mac.com']))->get($uri)->assertNotFound();
})->with([
    '/download/..%2F..%2Fetc%2Fpasswd',
    '/download/foo/bar',
    '/download/..',
    '/download/.env',
]);

it('returns 404 for a file that does not exist', function () {
    $this->actingAs(User::factory()->create(['email' => 'ish@mac.com']))
        ->get(route('download.filtered', ['filename' => 'missing.json']))
        ->assertNotFound();
});

it('builds the download link from the named route for zip and non-zip names', function (string $name) {
    $job = new ProcessResourceFile(jobId: 'x', path: 'in.json', regionIds: []);

    expect(callProtectedMethod($job, 'generateDownloadUrl', [$name]))->toBe(route('download.filtered', ['filename' => $name]));
})->with(['filtered_x.zip', 'filtered_x.json']);
