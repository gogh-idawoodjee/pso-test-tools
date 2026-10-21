<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Message texts now live on the Settings page. Carries the newest ready upload over
 * as the message_texts setting, then deletes every message_texts export row and object.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exportRows = DB::table('export_files')->where('kind', 'message_texts')->orderByDesc('created_at')->get();

        if ($exportRows->isEmpty()) {
            return;
        }

        $newestReady = $exportRows->firstWhere('status', 'ready');

        if ($newestReady !== null) {
            DB::table('app_settings')->updateOrInsert(['key' => 'message_texts'], [
                'value' => json_encode([
                    'status' => 'ready',
                    'file_name' => $newestReady->original_filename,
                    'uploaded_by' => (int) $newestReady->uploaded_by_user_id,
                    'uploaded_at' => CarbonImmutable::parse($newestReady->created_at)->toIso8601String(),
                    'languages' => [],
                    'rows' => 0,
                    'error' => null,
                ], JSON_THROW_ON_ERROR),
                'updated_by' => (int) $newestReady->uploaded_by_user_id,
                'updated_at' => now(),
            ]);
        }

        $disk = Storage::disk((string) config('exports.disk', 'r2'));

        foreach ($exportRows as $exportRow) {
            try {
                $scopeName = preg_replace('/[^a-z]/', '', strtolower(class_basename((string) $exportRow->scope_type))) ?: 'scope';
                $extension = in_array($exportRow->content_type, ['xml', 'json', 'zip', 'xlsx', 'csv'], true) ? $exportRow->content_type : 'bin';
                $disk->delete("exports/{$scopeName}/{$exportRow->scope_id}/{$exportRow->id}.{$extension}");
            } catch (Throwable) {
                continue;
            }
        }

        DB::table('export_files')->where('kind', 'message_texts')->delete();

        Cache::forget('app_settings');
    }

    public function down(): void {}
};
