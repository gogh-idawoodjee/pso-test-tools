<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Jobs\SummariseExportFileJob;
use App\Models\ExportFile;
use App\Models\PsoMessageText;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Support\Exports\ExportSummariser;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid()]);
});

function runMessageTextsJob(string $xml): ExportFile
{
    $user = User::factory()->create();
    $file = ExportFile::factory()->create([
        'scope_type' => $user->getMorphClass(), 'scope_id' => $user->getKey(),
        'kind' => ExportKind::MessageTexts, 'content_type' => 'xml', 'status' => ExportFileStatus::Summarising,
    ]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), $xml);

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(ExportSummariser::class));

    return $file->refresh();
}

it('summarises an Unfiled message texts file and fills the global table', function () {
    $file = runMessageTextsJob('<DsSystemData>'
        .'<Message_Text><message_id>1</message_id><language_id>en</language_id><text>One</text></Message_Text>'
        .'<Message_Text><message_id>2</message_id><language_id>en</language_id><text>Two</text></Message_Text>'
        .'<Message_Text><message_id>1</message_id><language_id>fr</language_id><text>Un</text></Message_Text>'
        .'</DsSystemData>');

    expect($file->status)->toBe(ExportFileStatus::Ready)
        ->and($file->summary['message_text_count'])->toBe(3)
        ->and($file->summary['languages'])->toBe(['en', 'fr'])
        ->and($file->summary['root'])->toBe('DsSystemData')
        ->and(PsoMessageText::count())->toBe(3);
});

it('fails the file when it holds no message texts', function () {
    $file = runMessageTextsJob('<DsSystemData><Profile_Parameter/></DsSystemData>');

    expect($file->status)->toBe(ExportFileStatus::Failed)
        ->and($file->error_message)->toContain('No Message_Text rows');
});
