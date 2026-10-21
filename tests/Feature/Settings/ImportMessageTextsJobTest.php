<?php

use App\Jobs\ImportMessageTextsJob;
use App\Models\PsoMessageText;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Support\Exports\MessageTextsImporter;
use App\Support\Settings\AppSettings;
use App\Support\Settings\SettingsFileStore;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid()]);
});

function runImportMessageTextsJob(string $key, string $fileName): void
{
    $user = User::factory()->create();

    (new ImportMessageTextsJob($key, $fileName, $user->id))->handle(
        app(SettingsFileStore::class),
        app(ExportStorage::class),
        app(MessageTextsImporter::class),
        app(AppSettings::class),
    );
}

it('imports the texts, records the outcome and removes the upload', function () {
    $key = SettingsFileStore::messageTextsKey('xml');
    Storage::disk('r2')->put($key, '<DsSystemData>'
        .'<Message_Text><message_id>1</message_id><language_id>en</language_id><text>One</text></Message_Text>'
        .'<Message_Text><message_id>2</message_id><language_id>en</language_id><text>Two</text></Message_Text>'
        .'<Message_Text><message_id>1</message_id><language_id>fr</language_id><text>Un</text></Message_Text>'
        .'</DsSystemData>');

    runImportMessageTextsJob($key, 'MessageTexts-en.xml');

    $record = app(AppSettings::class)->messageTexts();
    expect($record['status'])->toBe('ready')
        ->and($record['languages'])->toBe(['en', 'fr'])
        ->and($record['rows'])->toBe(3)
        ->and(PsoMessageText::count())->toBe(3)
        ->and(Storage::disk('r2')->exists($key))->toBeFalse();
});

it('records a failure in plain words and still removes the upload', function () {
    $key = SettingsFileStore::messageTextsKey('xml');
    Storage::disk('r2')->put($key, '<DsSystemData></DsSystemData>');

    runImportMessageTextsJob($key, 'bad.xml');

    expect(app(AppSettings::class)->messageTexts()['status'])->toBe('failed')
        ->and(app(AppSettings::class)->messageTexts()['error'])->toBe('No Message_Text rows in this file.')
        ->and(Storage::disk('r2')->exists($key))->toBeFalse();
});

it('marks a vanished upload as failed', function () {
    runImportMessageTextsJob(SettingsFileStore::messageTextsKey('xml'), 'gone.xml');

    expect(app(AppSettings::class)->messageTexts()['status'])->toBe('failed');
});
