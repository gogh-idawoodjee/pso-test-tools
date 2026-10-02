<?php

namespace App\Filament\Pages;

use App\Jobs\RunSysCompareJob;
use App\Support\SysCompare\Storage\SysCompareStorage;
use App\Support\SysCompare\SysCompareArtifact;
use App\Traits\FilamentJobMonitoring;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * Compares two or more PSO system data exports (DsSystemData XML) and produces an
 * HTML report, a CSV bundle and an Excel workbook, with a loud banner when the
 * environments are not all on the same PSO version.
 *
 * Sys files hold customer data, so this page never uses Livewire's file uploads
 * (whose temporary disk is the shared R2 bucket). The drop zone posts each file to
 * SysCompareUploadController, which stores it on a private local disk, and the page
 * only ever holds an upload id. RestrictsFileUploadsToSchemaComponents makes any
 * attempt to push a file through Livewire on this page fail with a 403.
 *
 * The comparison itself runs in RunSysCompareJob; this page polls its progress.
 */
class SysFileCompare extends Page
{
    use FilamentJobMonitoring, RestrictsFileUploadsToSchemaComponents;

    private const int NAME_MAX_LENGTH = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Additional Tools';

    protected static ?string $navigationLabel = 'PSO Sys File Compare';

    protected static ?string $title = 'PSO Sys File Compare';

    protected static ?string $slug = 'sys-file-compare';

    protected string $view = 'filament.pages.sys-file-compare';

    /**
     * Form state (not `$data`, which FilamentJobMonitoring already uses): environments (upload id, file name, display name per file, in column order),
     * baseline (the key of one environment) and definitions (an optional ParamDefinitions.csv upload).
     *
     * @var array<string, mixed>|null
     */
    public ?array $formData = [];

    /** The run whose results are shown. */
    #[Locked]
    public ?string $runId = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $summary = null;

    #[Locked]
    public ?string $failureMessage = null;

    public function mount(): void
    {
        // RunSysCompareJob's own queue timeout is 300s; leave it room to fail on its own first.
        $this->jobTimeoutSeconds = 320;

        $this->resetForm();
    }

    protected function getForms(): array
    {
        return ['form'];
    }

    public function form(Schema $form): Schema
    {
        $maxFiles = (int) config('sys-compare.max_files');

        return $form
            ->statePath('formData')
            ->components([
                Section::make('Sys files')
                    ->description("Drop two or more PSO system data exports (DsSystemData XML, up to {$maxFiles} files). Give each environment a name and choose one baseline: every other environment is compared against it.")
                    ->schema([
                        View::make('filament.pages.sys-file-compare.dropzone')
                            ->viewData(['kind' => 'sys'])
                            ->columnSpanFull(),
                        Repeater::make('environments')
                            ->label('Environments (the order here is the column order in the results)')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('fileName'),
                                Hidden::make('sizeLabel'),
                                Text::make(static fn (Get $get): string => $get('fileName').' - '.$get('sizeLabel')),
                                TextInput::make('name')
                                    ->label('Name')
                                    ->required()
                                    ->maxLength(self::NAME_MAX_LENGTH)
                                    ->distinct()
                                    ->live(onBlur: true),
                            ])
                            ->itemLabel(fn (array $state): string => filled($state['name'] ?? null) ? $state['name'] : 'Unnamed environment')
                            ->addable(false)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->deleteAction(fn (Action $action): Action => $action->before(function (array $arguments, Repeater $component): void {
                                $this->discardUpload(Arr::get($component->getRawItemState($arguments['item']), 'id'));
                            }))
                            ->visible(fn (): bool => $this->environmentItems() !== [])
                            ->columnSpanFull(),
                        Radio::make('baseline')
                            ->label('Baseline')
                            ->helperText('Cells that differ from the baseline are highlighted amber, and the tally counts rows relative to it.')
                            ->options(fn (Get $get): array => $this->baselineOptions($get('environments')))
                            ->inline()
                            ->live()
                            ->visible(fn (): bool => $this->environmentItems() !== []),
                    ]),
                Section::make('Parameter definitions (optional)')
                    ->description('Parameters are described using the official PSO descriptions. Upload a ParamDefinitions.csv (columns Parameter, Definition, Note, Basis) to add to them: a Definition replaces the official text, and a Note is added after it. Your rows replace the built-in row for the same parameter. A template listing the parameters that still have no definition is offered with the results.')
                    ->schema([
                        View::make('filament.pages.sys-file-compare.dropzone')
                            ->viewData(['kind' => 'definitions'])
                            ->columnSpanFull(),
                    ])
                    ->collapsed()
                    ->collapsible(),
                Callout::make('Before you can run')
                    ->description(fn (): string => implode(' ', $this->blockingReasons()))
                    ->warning()
                    ->visible(fn (): bool => $this->environmentItems() !== [] && $this->blockingReasons() !== []),
                Actions::make([
                    Action::make('run')
                        ->label('Run comparison')
                        ->icon(Heroicon::OutlinedPlay)
                        ->disabled(fn (): bool => $this->blockingReasons() !== [])
                        ->action(fn () => $this->run()),
                ]),
            ]);
    }

    /**
     * Called by the drop zone once a file has been stored by the upload endpoint.
     * The client only supplies an id and a display name: the file itself is checked on disk.
     */
    public function addUploadedFile(string $kind, string $id, string $fileName): void
    {
        $storage = app(SysCompareStorage::class);
        $userId = $this->userId();

        if (! SysCompareStorage::isValidId($id) || $storage->uploadPath($userId, $id) === null) {
            $this->notifyDanger('File not found', 'That upload is no longer available. Please add the file again.');

            return;
        }

        $fileName = $this->cleanFileName($fileName);

        if ($kind === 'definitions') {
            $this->discardUpload(Arr::get($this->formData, 'definitions.id'));
            $this->formData['definitions'] = ['id' => $id, 'fileName' => $fileName];

            return;
        }

        if (count($this->environmentItems()) >= (int) config('sys-compare.max_files')) {
            $storage->deleteUpload($userId, $id);
            $this->notifyWarning('Too many files', 'You can compare up to '.config('sys-compare.max_files').' files at a time.');

            return;
        }

        $key = (string) Str::uuid();

        $this->formData['environments'][$key] = [
            'id' => $id,
            'fileName' => $fileName,
            'sizeLabel' => Number::fileSize($storage->uploadSize($userId, $id), 1),
            'name' => Str::limit(Str::upper(pathinfo($fileName, PATHINFO_FILENAME)), self::NAME_MAX_LENGTH, ''),
        ];

        $this->formData['baseline'] ??= $key;
    }

    public function removeDefinitions(): void
    {
        $this->discardUpload(Arr::get($this->formData, 'definitions.id'));
        $this->formData['definitions'] = null;
    }

    public function run(): void
    {
        $reasons = $this->blockingReasons();

        if ($reasons !== []) {
            $this->notifyWarning('Not ready to run', implode(' ', $reasons));

            return;
        }

        $state = $this->form->getState();
        $storage = app(SysCompareStorage::class);
        $userId = $this->userId();

        $files = [];
        $baselineKey = $this->baselineKey();
        $baselineName = '';

        foreach ($this->environmentItems() as $key => $item) {
            if ($storage->uploadPath($userId, (string) $item['id']) === null) {
                $this->notifyDanger('File not found', "{$item['fileName']} is no longer available (uploads expire after ".config('sys-compare.upload_ttl_minutes').' minutes). Please add it again.');
                unset($this->formData['environments'][$key]);

                return;
            }

            $name = trim((string) Arr::get($state, "environments.{$key}.name", $item['name']));
            $files[] = ['id' => (string) $item['id'], 'name' => $name, 'fileName' => (string) $item['fileName']];

            if ($key === $baselineKey) {
                $baselineName = $name;
            }
        }

        $definitions = Arr::get($this->formData, 'definitions');

        if ($definitions !== null && $storage->uploadPath($userId, (string) $definitions['id']) === null) {
            $this->removeDefinitions();
            $this->notifyDanger('File not found', 'The definitions file is no longer available. Please add it again.');

            return;
        }

        $this->startJob(RunSysCompareJob::CACHE_PREFIX);
        $this->runId = SysCompareStorage::newId();
        $this->summary = null;
        $this->failureMessage = null;

        RunSysCompareJob::dispatch($this->jobId, $userId, $this->runId, $files, $baselineName, $definitions);
    }

    /**
     * Polled while a comparison runs.
     */
    public function checkStatus(): void
    {
        if (! $this->jobId) {
            return;
        }

        $this->progress = $this->getJobProgress();
        $this->status = $this->getJobStatus();

        if ($this->isJobTimedOut()) {
            $this->failureMessage = 'The comparison took too long and was stopped. Please try again with fewer or smaller files.';
            $this->handleJobTimeout();

            return;
        }

        if ($this->status === 'complete') {
            $this->handleCompletion();

            return;
        }

        if ($this->status === 'failed') {
            $this->handleFailure();
        }
    }

    public function startNewComparison(): void
    {
        $this->summary = null;
        $this->runId = null;
        $this->failureMessage = null;
    }

    /**
     * Whatever is left in the form once an upload has been consumed or removed must be dropped,
     * and the baseline must always point at a file that is still there.
     */
    public function dehydrate(): void
    {
        $this->formData['baseline'] = $this->baselineKey();
    }

    /**
     * @return list<string> why the comparison cannot run yet; empty when it can
     */
    public function blockingReasons(): array
    {
        $items = $this->environmentItems();
        $reasons = [];

        if ($this->jobId && ! in_array($this->status, ['complete', 'failed', 'cancelled'], true)) {
            return ['A comparison is already running.'];
        }

        if (count($items) < 2) {
            $reasons[] = 'Add at least two sys files.';
        }

        $names = array_map(static fn (array $item): string => trim((string) ($item['name'] ?? '')), $items);

        if (in_array('', $names, true)) {
            $reasons[] = 'Give every file a name.';
        }

        $duplicates = collect($names)->filter()->groupBy(static fn (string $name): string => Str::lower($name))->filter(static fn ($group): bool => $group->count() > 1);

        if ($duplicates->isNotEmpty()) {
            $reasons[] = 'File names must be unique ('.$duplicates->map(static fn ($group): string => (string) $group->first())->implode(', ').' appears more than once).';
        }

        if ($items !== [] && $this->baselineKey() === null) {
            $reasons[] = 'Choose a baseline.';
        }

        return $reasons;
    }

    public function downloadUrl(SysCompareArtifact $artifact, bool $inline = false): string
    {
        return route('filament.app.sys-compare.runs.download', [
            'run' => $this->runId,
            'artifact' => $artifact->value,
            ...($inline ? ['inline' => 1] : []),
        ]);
    }

    public function hasTemplate(): bool
    {
        return (bool) ($this->summary['templateAvailable'] ?? false);
    }

    public function retentionMinutes(): int
    {
        return (int) config('sys-compare.run_ttl_minutes');
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function environmentItems(): array
    {
        return array_filter((array) Arr::get($this->formData, 'environments', []), is_array(...));
    }

    /**
     * The baseline's key, or the first file's key when the chosen one has been removed.
     */
    private function baselineKey(): ?string
    {
        $items = $this->environmentItems();
        $chosen = Arr::get($this->formData, 'baseline');

        if (is_string($chosen) && isset($items[$chosen])) {
            return $chosen;
        }

        return array_key_first($items) !== null ? (string) array_key_first($items) : null;
    }

    /**
     * @param  array<string, array<string, string>>|null  $environments
     * @return array<string, string>
     */
    private function baselineOptions(?array $environments): array
    {
        return collect($environments ?? [])
            ->mapWithKeys(static fn (array $item, string $key): array => [$key => filled($item['name'] ?? null) ? $item['name'] : '(unnamed)'])
            ->all();
    }

    private function handleCompletion(): void
    {
        $contents = app(SysCompareStorage::class)->readRunFile($this->userId(), (string) $this->runId, SysCompareStorage::SUMMARY_FILE);

        $this->summary = $contents !== null ? json_decode($contents, true) : null;

        if ($this->summary === null) {
            $this->failureMessage = 'The results are no longer available. Please run the comparison again.';
            $this->notifyDanger('Comparison failed', $this->failureMessage);
        } else {
            $this->notifySuccess('Comparison complete', 'The report, CSV bundle and Excel workbook are ready below.');
        }

        // The job has already deleted the uploads, so the form starts again from empty.
        $this->resetForm();
        $this->resetJobState();
    }

    private function handleFailure(): void
    {
        $this->failureMessage = (string) Cache::get($this->getJobCacheKey('message'), 'The comparison could not be completed.');
        $this->notifyDanger('Comparison failed', $this->failureMessage);

        // The job deletes the uploads whatever happens, so they must be added again.
        $this->resetForm();
        $this->resetJobState();
    }

    private function discardUpload(mixed $id): void
    {
        if (is_string($id) && $id !== '') {
            app(SysCompareStorage::class)->deleteUpload($this->userId(), $id);
        }
    }

    private function resetForm(): void
    {
        $this->form->fill(['environments' => [], 'baseline' => null, 'definitions' => null]);
    }

    private function cleanFileName(string $fileName): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $fileName))) ?? '';

        return Str::limit(trim($clean) !== '' ? $clean : 'sys-file.xml', 150, '');
    }

    private function userId(): int
    {
        return (int) auth()->id();
    }
}
