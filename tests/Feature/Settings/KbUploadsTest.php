<?php

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Support\Settings\AppSettings;
use App\Support\Settings\InvalidKbFile;
use App\Support\Settings\KbFiles;
use App\Support\Settings\SettingsFileStore;
use App\Support\SysCompare\ParameterCatalog;
use App\Tools\LoadAudit\LoadAuditCommand;
use App\Tools\LoadAudit\PsoToolsCheckout;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3']);
    Http::fake(['*' => Http::response('boom', 500)]);
    $this->actingAs(User::factory()->create());
});

it('reads an uploaded release notes PDF into the watchlist and records the build', function () {
    Livewire::test(Settings::class)
        ->callAction('uploadReleaseNotes', ['file' => UploadedFile::fake()->createWithContent('Public Release Notes.pdf', '%PDF-1.4 ok')])
        ->assertHasNoActionErrors();

    $record = app(AppSettings::class)->releaseNotes();
    expect($record)->toMatchArray(['file_name' => 'Public Release Notes.pdf', 'build' => '6.18.0.5', 'date' => '01/10/2026', 'fixes' => 4700, 'kept' => 1001])
        ->and(Storage::disk('r2')->get(SettingsFileStore::RELEASE_NOTES))->toContain('| 6.18.0.5 | 01/10/2026 | a fix |');
    Livewire::test(Settings::class)->assertSee('up to build 6.18.0.5')->assertSee('1,001 of 4,700 fixes');
});

it('refuses a PDF the reader cannot use and keeps what was there', function (string $content, string $message) {
    Livewire::test(Settings::class)
        ->callAction('uploadReleaseNotes', ['file' => UploadedFile::fake()->createWithContent('notes.pdf', $content)])
        ->assertHasActionErrors(['file']);

    expect(app(AppSettings::class)->releaseNotes())->toBeNull()
        ->and(Storage::disk('r2')->exists(SettingsFileStore::RELEASE_NOTES))->toBeFalse();
})->with([
    'not release notes' => ['%PDF BAD', 'No fixes found'],
    'no pdftotext on the server' => ['%PDF NOPDF', 'poppler-utils'],
]);

it('says in plain words when the server cannot read PDFs', function () {
    expect(fn () => KbFiles::build(PsoToolsCheckout::fromConfig(), base_path('tests/Fixtures/pso-tools/kb/build_release_notes.py'),
        tap(tempnam(sys_get_temp_dir(), 'kb'), fn ($p) => file_put_contents($p, 'NOPDF')), sys_get_temp_dir().'/kb-out.md'))
        ->toThrow(InvalidKbFile::class, 'This server cannot read PDFs yet: poppler-utils needs installing on it (apt install poppler-utils).');
});

it('builds the parameter catalog from the saved docs page with its release', function () {
    Livewire::test(Settings::class)
        ->callAction('uploadParamReference', ['file' => UploadedFile::fake()->createWithContent('Parameters - IFS PSO Documentation.html', '<html>ok</html>'), 'pso_version' => '6.19'])
        ->assertHasNoActionErrors();

    expect(app(AppSettings::class)->paramReference())->toMatchArray(['rows' => 1, 'pso_version' => '6.19'])
        ->and(Storage::disk('r2')->get(SettingsFileStore::PARAM_REFERENCE))->toContain('DSE,Mode');

    $catalog = KbFiles::catalog(sys_get_temp_dir().'/kb-'.uniqid());
    expect($catalog->psoVersion)->toBe('6.19')->and($catalog->isEmpty())->toBeFalse();
});

it('refuses a page the builder cannot read, and a release that is not a release', function () {
    Livewire::test(Settings::class)
        ->callAction('uploadParamReference', ['file' => UploadedFile::fake()->createWithContent('p.html', '<html>BAD</html>'), 'pso_version' => '6.19'])
        ->assertHasActionErrors(['file']);
    Livewire::test(Settings::class)
        ->callAction('uploadParamReference', ['file' => UploadedFile::fake()->createWithContent('p.html', '<html>ok</html>'), 'pso_version' => 'latest'])
        ->assertHasActionErrors(['pso_version']);

    expect(app(AppSettings::class)->paramReference())->toBeNull();
});

it('falls back to the shipped files and the built-in catalog when nothing is uploaded', function () {
    $checkout = PsoToolsCheckout::fromConfig();
    $dir = sys_get_temp_dir().'/kb-'.uniqid();
    @mkdir($dir);

    expect(KbFiles::watchlistPath($checkout, $dir))->toBe($checkout->watchlistFile())
        ->and(KbFiles::paramsPath($checkout, $dir))->toBe($checkout->paramsFile())
        ->and(KbFiles::catalog($dir)->psoVersion)->toBe(ParameterCatalog::BUILT_IN_PSO_VERSION);
});

it('hands the Load audit the uploaded watchlist and catalog, copied into the run folder', function () {
    Storage::disk('r2')->put(SettingsFileStore::RELEASE_NOTES, '# mine');
    Storage::disk('r2')->put(SettingsFileStore::PARAM_REFERENCE, 'application,parameter_id');
    $checkout = PsoToolsCheckout::fromConfig();
    $dir = sys_get_temp_dir().'/kb-'.uniqid();
    @mkdir($dir);

    $argv = LoadAuditCommand::build($checkout, $dir, ['load' => $dir.'/l.xml'], [], 'UTC', null, null,
        KbFiles::paramsPath($checkout, $dir), KbFiles::watchlistPath($checkout, $dir));

    expect($argv[array_search('--watchlist', $argv, true) + 1])->toBe($dir.'/release-notes-watchlist.md')
        ->and($argv[array_search('--params', $argv, true) + 1])->toBe($dir.'/pso-parameters-reference.csv')
        ->and(file_get_contents($dir.'/release-notes-watchlist.md'))->toBe('# mine');
});

it('goes back to the shipped files when the uploads are removed', function () {
    Storage::disk('r2')->put(SettingsFileStore::RELEASE_NOTES, '# mine');
    app(AppSettings::class)->put(AppSettings::RELEASE_NOTES, ['file_name' => 'x.pdf', 'build' => '6.18.0.5']);

    Livewire::test(Settings::class)->callAction('removeReleaseNotes');

    expect(app(AppSettings::class)->releaseNotes())->toBeNull()
        ->and(Storage::disk('r2')->exists(SettingsFileStore::RELEASE_NOTES))->toBeFalse();
});
