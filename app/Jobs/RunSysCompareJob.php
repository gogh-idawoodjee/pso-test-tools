<?php

namespace App\Jobs;

use App\Support\Exports\ExportStorage;
use App\Support\Settings\AppSettings;
use App\Support\Settings\KbFiles;
use App\Support\Settings\SettingsFileStore;
use App\Support\SysCompare\Comparer;
use App\Support\SysCompare\DefinitionsCsv;
use App\Support\SysCompare\Exceptions\InvalidComparison;
use App\Support\SysCompare\Exceptions\InvalidDefinitionsFile;
use App\Support\SysCompare\Exceptions\InvalidSysFile;
use App\Support\SysCompare\ParamDefinitions;
use App\Support\SysCompare\ParameterCatalog;
use App\Support\SysCompare\Render\CsvBundle;
use App\Support\SysCompare\Render\DefinitionsTemplate;
use App\Support\SysCompare\Render\HtmlReport;
use App\Support\SysCompare\Render\XlsxWorkbook;
use App\Support\SysCompare\RunSummary;
use App\Support\SysCompare\Storage\SysCompareStorage;
use App\Support\SysCompare\SysCompareArtifact;
use App\Support\SysCompare\SysEnvironment;
use App\Support\SysCompare\SysFileReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Compares the uploaded sys files and writes the HTML, CSV and Excel outputs,
 * describing parameters with the built-in definitions plus the ParamDefinitions.csv
 * stored on the Settings page, when there is one.
 *
 * Privacy: uploads are always deleted when the job ends, whether it succeeds or
 * fails. Nothing from the files is logged, and failure messages name the file
 * and the reason only. Progress is reported through the cache keys the
 * FilamentJobMonitoring trait polls: {prefix}:{jobId}:progress|status, plus
 * :message for a failure.
 */
class RunSysCompareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string CACHE_PREFIX = 'sys-compare-job';

    private const int CACHE_SECONDS = 7200;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  list<array{id: string, name: string, fileName: string}>  $files  uploaded sys files in display order
     */
    public function __construct(
        public string $jobId,
        public int $userId,
        public string $runId,
        public array $files,
        public string $baseline,
    ) {}

    public function handle(SysCompareStorage $storage, SysFileReader $reader, Comparer $comparer): void
    {
        $this->report('processing', 5);

        try {
            $this->purgeExpired($storage);

            $environments = $this->readEnvironments($storage, $reader);
            $this->report('processing', 50);

            $result = $comparer->compare($environments, $this->baseline, $this->loadDefinitions(), catalog: $this->loadCatalog());
            $this->report('processing', 60);

            $storage->putRunFile($this->userId, $this->runId, SysCompareArtifact::Report->fileName(), app(HtmlReport::class)->render($result));
            $this->report('processing', 70);

            $csvPath = $storage->runFilePathForWriting($this->userId, $this->runId, SysCompareArtifact::Csv->fileName());
            app(CsvBundle::class)->write($result, $csvPath);
            $storage->shareWithGroup($csvPath);
            $this->report('processing', 80);

            $xlsxPath = $storage->runFilePathForWriting($this->userId, $this->runId, SysCompareArtifact::Xlsx->fileName());
            app(XlsxWorkbook::class)->write($result, $xlsxPath);
            $storage->shareWithGroup($xlsxPath);
            $this->report('processing', 92);

            $template = DefinitionsTemplate::csv($result);

            if ($template !== null) {
                $storage->putRunFile($this->userId, $this->runId, SysCompareArtifact::Template->fileName(), $template);
            }

            $storage->putRunFile($this->userId, $this->runId, SysCompareStorage::SUMMARY_FILE, json_encode(RunSummary::fromResult($result)->toArray(), JSON_THROW_ON_ERROR));

            $this->report('complete', 100);
        } catch (InvalidSysFile|InvalidComparison|InvalidDefinitionsFile $exception) {
            $this->fail($storage, $exception->getMessage());
        } catch (Throwable $exception) {
            // Class and location only: a message could quote content from the files.
            Log::error('PSO Sys File Compare failed', [
                'exception' => $exception::class,
                'where' => basename($exception->getFile()).':'.$exception->getLine(),
            ]);

            $this->fail($storage, 'The comparison could not be completed. Check that every file is a complete PSO system data export and try again.');
        } finally {
            $this->deleteUploads($storage);
        }
    }

    /**
     * @return list<SysEnvironment>
     */
    private function readEnvironments(SysCompareStorage $storage, SysFileReader $reader): array
    {
        $environments = [];
        $count = max(1, count($this->files));

        foreach ($this->files as $index => $file) {
            $path = $storage->uploadPath($this->userId, $file['id']);

            if ($path === null) {
                $this->logMissingUpload($storage, $file['id']);

                throw new InvalidSysFile("{$file['fileName']}: the upload could not be found (uploads are removed after ".config('sys-compare.upload_ttl_minutes', 120).' minutes). Please add the file again. If this happens straight after adding it, the queue worker may need a restart or access to the upload folder.');
            }

            $environments[] = new SysEnvironment($file['name'], $reader->read($path, $file['fileName']));
            $this->report('processing', 5 + (int) round(45 * ($index + 1) / $count));
        }

        return $environments;
    }

    /** The parameter catalog uploaded on the Settings page, else the built-in one. */
    private function loadCatalog(): ParameterCatalog
    {
        $exportStorage = app(ExportStorage::class);
        $scratchDirectory = $exportStorage->scratchDirectoryFor((string) Str::uuid());

        try {
            return KbFiles::catalog($scratchDirectory);
        } finally {
            $exportStorage->removeScratch($scratchDirectory);
        }
    }

    private function loadDefinitions(): ParamDefinitions
    {
        $definitions = ParamDefinitions::builtIn();
        $fileStore = app(SettingsFileStore::class);

        if (! $fileStore->exists(SettingsFileStore::PARAM_DEFINITIONS)) {
            return $definitions;
        }

        $fileName = (string) (app(AppSettings::class)->paramDefinitions()['file_name'] ?? 'ParamDefinitions.csv');
        $exportStorage = app(ExportStorage::class);
        $scratchDirectory = $exportStorage->scratchDirectoryFor((string) Str::uuid());

        try {
            $path = $fileStore->readToScratch(SettingsFileStore::PARAM_DEFINITIONS, $scratchDirectory);

            return $definitions->withUserDefinitions(DefinitionsCsv::parse($path, $fileName));
        } finally {
            $exportStorage->removeScratch($scratchDirectory);
        }
    }

    /**
     * Tidying old files is best-effort: it must never stop a comparison from running.
     */
    private function purgeExpired(SysCompareStorage $storage): void
    {
        try {
            $storage->purgeExpired();
        } catch (Throwable $exception) {
            Log::warning('PSO Sys File Compare could not purge expired files', ['exception' => $exception::class]);
        }
    }

    /**
     * Says why a worker may not see an upload that the web request just stored, without
     * recording anything from the file itself.
     */
    private function logMissingUpload(SysCompareStorage $storage, string $uploadId): void
    {
        $root = $storage->disk()->path('');

        Log::warning('PSO Sys File Compare: the worker could not find an upload', [
            'uploadId' => $uploadId,
            'workerUser' => function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : 'unknown',
            'diskRootReadable' => is_readable($root),
            'diskRootTraversable' => is_executable($root),
            'diskConfigured' => config('filesystems.disks.'.config('sys-compare.disk', 'sys-compare')) !== null,
        ]);
    }

    private function deleteUploads(SysCompareStorage $storage): void
    {
        foreach ($this->files as $file) {
            $storage->deleteUpload($this->userId, $file['id']);
        }
    }

    private function fail(SysCompareStorage $storage, string $message): void
    {
        $storage->deleteRun($this->userId, $this->runId);

        Cache::put($this->key('message'), $message, self::CACHE_SECONDS);
        $this->report('failed', 100);
    }

    private function report(string $status, int $progress): void
    {
        Cache::put($this->key('status'), $status, self::CACHE_SECONDS);
        Cache::put($this->key('progress'), $progress, self::CACHE_SECONDS);
    }

    private function key(string $suffix): string
    {
        return self::CACHE_PREFIX.':'.$this->jobId.':'.$suffix;
    }
}
