<?php

namespace App\Jobs;

use App\Support\Exports\ExportStorage;
use App\Support\Exports\InvalidExportException;
use App\Support\Exports\MessageTextsImporter;
use App\Support\Settings\AppSettings;
use App\Support\Settings\SettingsFileStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Imports the message texts file uploaded on the settings page into
 * pso_message_texts, records the outcome on the message_texts setting and always
 * removes the upload and its scratch folder.
 */
class ImportMessageTextsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $objectKey, public string $fileName, public ?int $userId) {}

    public function handle(SettingsFileStore $files, ExportStorage $storage, MessageTextsImporter $importer, AppSettings $settings): void
    {
        $settings->put(AppSettings::MESSAGE_TEXTS, $this->record('importing'), $this->userId);

        $scratch = $storage->scratchDirectoryFor((string) Str::uuid());

        try {
            $path = $files->readToScratch($this->objectKey, $scratch);
            $result = $importer->import($path);

            $settings->put(AppSettings::MESSAGE_TEXTS, $this->record('ready', $result['languages'], $result['rows']), $this->userId);
        } catch (InvalidExportException|RuntimeException $exception) {
            $settings->put(AppSettings::MESSAGE_TEXTS, $this->record('failed', error: $exception->getMessage()), $this->userId);
        } catch (Throwable $exception) {
            Log::warning('Message texts import failed', ['object_key' => $this->objectKey, 'error' => $exception->getMessage()]);
            $settings->put(AppSettings::MESSAGE_TEXTS, $this->record('failed', error: 'The file could not be imported: '.$exception->getMessage()), $this->userId);
        } finally {
            $files->delete($this->objectKey);
            $storage->removeScratch($scratch);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(AppSettings::class)->put(
            AppSettings::MESSAGE_TEXTS,
            $this->record('failed', error: 'The file could not be imported: '.$exception->getMessage()),
            $this->userId,
        );
    }

    /**
     * @param  list<string>  $languages
     * @return array{status: string, file_name: string, uploaded_by: ?int, uploaded_at: string, languages: list<string>, rows: int, error: ?string}
     */
    private function record(string $status, array $languages = [], int $rows = 0, ?string $error = null): array
    {
        return [
            'status' => $status,
            'file_name' => $this->fileName,
            'uploaded_by' => $this->userId,
            'uploaded_at' => now()->toIso8601String(),
            'languages' => $languages,
            'rows' => $rows,
            'error' => $error,
        ];
    }
}
