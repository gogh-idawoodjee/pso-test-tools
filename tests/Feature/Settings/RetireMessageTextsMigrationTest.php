<?php

use App\Models\User;
use App\Support\Settings\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2']);
});

function runRetireMessageTextsMigration(): void
{
    (require database_path('migrations/2026_10_22_000002_retire_message_texts_export_kind.php'))->up();
    Cache::forget(AppSettings::CACHE_KEY);
}

function insertMessageTextsExportRow(User $user, string $status, string $fileName, string $createdAt): string
{
    $id = (string) Str::uuid();

    DB::table('export_files')->insert([
        'id' => $id,
        'scope_type' => $user->getMorphClass(),
        'scope_id' => (string) $user->id,
        'kind' => 'message_texts',
        'uploaded_by_user_id' => $user->id,
        'original_filename' => $fileName,
        'stored_path' => '',
        'file_size_bytes' => 10,
        'content_type' => 'xml',
        'status' => $status,
        'expires_at' => now()->addHour(),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    Storage::disk('r2')->put("exports/user/{$user->id}/{$id}.xml", '<x/>');

    return $id;
}

it('moves the newest ready message texts export into the setting and removes the rows and objects', function () {
    $user = User::factory()->create();
    $failedId = insertMessageTextsExportRow($user, 'failed', 'old.xml', '2026-01-01 10:00:00');
    $readyId = insertMessageTextsExportRow($user, 'ready', 'MessageTexts-en.xml', '2026-01-02 11:12:13');

    runRetireMessageTextsMigration();

    expect(DB::table('export_files')->where('kind', 'message_texts')->count())->toBe(0)
        ->and(Storage::disk('r2')->exists("exports/user/{$user->id}/{$failedId}.xml"))->toBeFalse()
        ->and(Storage::disk('r2')->exists("exports/user/{$user->id}/{$readyId}.xml"))->toBeFalse()
        ->and(app(AppSettings::class)->messageTexts())->toBe([
            'status' => 'ready',
            'file_name' => 'MessageTexts-en.xml',
            'uploaded_by' => $user->id,
            'uploaded_at' => CarbonImmutable::parse('2026-01-02 11:12:13')->toIso8601String(),
            'languages' => [],
            'rows' => 0,
            'error' => null,
        ]);
});

it('leaves the setting empty when there are no message texts exports', function () {
    runRetireMessageTextsMigration();

    expect(app(AppSettings::class)->messageTexts())->toBeNull();
});
