<?php

namespace App\Filament\Pages;

use App\Jobs\ProcessResourceFile;
use App\Models\Environment;
use App\Traits\FilamentJobMonitoring;
use App\Traits\FormTrait;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * Filament page that lets users upload a PSO load file (JSON), preview its contents,
 * and filter it down by region, resource, activity type, activity, and date range.
 *
 * The workflow is two-phase:
 *  1. **Dry run** — upload a file, the job parses it and caches the available IDs
 *     (regions, resources, activities, activity types) so the dropdowns populate.
 *  2. **Filter run** — user selects which items to keep, submits again with dryRun off,
 *     and the job produces a filtered JSON file for download.
 *
 * All heavy processing is handled by the queued ProcessResourceFile job.
 * This page polls the job's cache keys for progress, status, and results.
 */
class FilterLoadFile extends Page
{
    use FilamentJobMonitoring, FormTrait;

    private const string JOB_TYPE = 'resource-job';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static string|BackedEnum|null $activeNavigationIcon = 'heroicon-s-funnel';

    protected string $view = 'filament.pages.filter-load-file';

    protected static string|UnitEnum|null $navigationGroup = 'Additional Tools';

    // File upload and processing properties
    public ?array $upload = null;

    public ?string $startDate = null;

    public ?string $endDate = null;

    public ?Carbon $jobCreatedAt = null;

    public ?Carbon $overrideDatetime = null;

    public array $regionIds = [];

    public array $resourceIds = [];

    public array $activityIds = [];

    public bool $dryRun = true;

    public ?string $downloadUrl = null;

    public array $preview = [];

    public array $availableActivityIds = [];

    public array $availableResourceIds = [];

    public array $availableRegionIds = [];

    public array $activityTypeIds = [];

    public array $availableActivityTypes = [];

    public array $activityTypeCounts = [];

    public bool $hasRunFilterJob = false;

    /**
     * Initialize the page: load environments, set up forms, and restore any in-progress job.
     */
    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();
        $this->isAuthenticationRequired = true;

        $this->env_form->fill();

        $this->form->fill([
            'upload' => null,
            'regionIds' => [],
            'dryRun' => true,
            'resourceIds' => [],
            'activityIds' => [],
        ]);

        $this->startDate = $this->startDate ? Carbon::parse($this->startDate) : null;
        $this->endDate = $this->endDate ? Carbon::parse($this->endDate) : null;

        // Restore progress if job is in progress
        if ($this->jobId) {
            $this->progress = $this->getJobProgress();
            $this->status = $this->getJobStatus();
        }
    }

    protected function getForms(): array
    {
        return ['env_form', 'form'];
    }

    /**
     * Define the main form: file upload, filtering options (regions, resources,
     * activity types, activities, date range, datetime override), and the dry-run toggle.
     */
    public function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make()
                ->schema([
                    FileUpload::make('upload')
                        ->label('Upload JSON File')
                        ->disk('r2')
                        ->directory('uploads')
                        ->maxSize(102400) // ← 100MB in kilobytes
                        ->acceptedFileTypes(['application/json'])
                        ->required()->columnSpan(2),
                    Fieldset::make('Filtering Options')->schema([
                        $this->createRegionSelector(),
                        $this->createResourceSelector(),
                        $this->createActivityTypeSelector(),
                        $this->createActivitySelector(),
                        DateTimePicker::make('startDate')
                            ->label('Start Date')
                            ->helperText('Optional. Filters data starting from this date.')
                            ->native(false)
                            ->seconds(false)
                            ->nullable()
                            ->reactive(),

                        DateTimePicker::make('endDate')
                            ->label('End Date')
                            ->helperText('Optional. Filters data up to this date.')
                            ->native(false)
                            ->seconds(false)
                            ->nullable()
                            ->after('startDate')
                            ->reactive(),

                        $this->createDatetimeOverrideField(),
                    ])->visible(fn () => $this->shouldShowDropdowns()),

                    Toggle::make('dryRun')
                        ->label('Get Data')
                        ->helperText('Required Prior to Filtering')
                        ->disabled(fn () => ! $this->shouldShowDropdowns())
                        ->dehydrated()
                        ->default(true)
                        ->live(),
                ])->columns(),
        ]
        );

    }

    protected function createRegionSelector(): Select
    {
        return Select::make('regionIds')
            ->label('Regions to Keep')
            ->multiple()
            ->options(fn () => $this->availableRegionIds)
            ->searchable()
            ->native(false)
            ->helperText('Only these regions will be kept. Others will be removed.')
            ->columnSpan(1);
    }

    protected function createResourceSelector(): Select
    {
        return Select::make('resourceIds')
            ->label('Filter to Specific Resources')
            ->multiple()
            ->options(fn () => $this->availableResourceIds)
            ->searchable()
            ->native(false)
            ->helperText('Optional. Only these resources will be included if selected.');
    }

    protected function createActivityTypeSelector(): Select
    {
        return Select::make('activityTypeIds')
            ->label('Activity Types')
            ->multiple()
            ->options(function () {
                return collect($this->availableActivityTypes)
                    ->filter(function ($label, $id) {
                        return ($this->activityTypeCounts[$id] ?? 0) > 0;
                    })
                    ->mapWithKeys(function ($label, $id) {
                        $count = $this->activityTypeCounts[$id];

                        return [$id => "{$label} ({$count})"];
                    });
            })
            ->searchable()
            ->native(false)
            ->reactive()
            ->live()
            ->helperText('Only shows types that have matching activities. Use this to filter by type.');
    }

    protected function createActivitySelector(): Select
    {
        return Select::make('activityIds')
            ->label('Filter to Specific Activities')
            ->multiple()
            ->options(function () {
                if (empty($this->activityTypeIds)) {
                    return $this->availableActivityIds;
                }

                // Only include activities matching selected types
                return collect($this->availableActivityIds)
                    ->filter(function ($label) {
                        return array_any($this->activityTypeIds, static fn ($typeId) => str_contains($label, $typeId));
                    })->all();
            })
            ->searchable()
            ->native(false)
            ->helperText('Optional. Only these activities will be included if selected.');
    }

    protected function createDatetimeOverrideField(): DateTimePicker
    {
        return DateTimePicker::make('overrideDatetime')
            ->label('Override Input Reference Datetime')
            ->prefixIcon('heroicon-o-calendar-days')
            ->helperText('Optional. Replaces the datetime in the Input_Reference.')
            ->native(false)
            ->seconds(false)
            ->nullable();
    }

    /**
     * Filtering options are only visible once a file has been uploaded
     * and the dry-run job has populated the available region IDs.
     */
    public function shouldShowDropdowns(): bool
    {
        return filled($this->upload) && ! empty($this->availableRegionIds);
    }

    /**
     * Validate filter selections and dispatch the ProcessResourceFile job.
     *
     * In dry-run mode, the job only parses the file and caches available IDs
     * so the filter dropdowns can populate. In filter mode, it applies all
     * selected filters and produces a downloadable JSON file.
     */
    public function submit(): void
    {
        if (! $this->dryRun && (($this->startDate && ! $this->endDate) || (! $this->startDate && $this->endDate))) {
            $this->notifyWarning('Date range incomplete', 'Both start and end dates must be filled or left blank.');

            return;
        }

        if (! $this->dryRun && empty($this->availableRegionIds)) {
            $this->notifyWarning('Please preview first', 'Run a dry run to load region, resource, and activity IDs.');

            return;
        }

        activity()->event('FilterLoadFile.submit')->log(json_encode([
            'dryRun' => $this->dryRun,
            'regionIds' => $this->regionIds,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        Log::info('[FilterLoadFile] ▶️ submit() called', [
            'dryRun' => $this->dryRun,
            'regionIds' => $this->regionIds,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ]);

        // Emit event for immediate UI feedback
        $this->dispatch('processingStarted');

        // Clean up previous job data if exists
        $this->cleanupPreviousJob();

        // Start a new job
        $this->startJob(self::JOB_TYPE);
        $this->progress = 5; // Set initial progress immediately

        // Save job creation time for timeout tracking
        $this->jobCreatedAt = Carbon::now();

        // Get form data and dispatch job
        $data = $this->prepareJobData();
        Log::debug('[FilterLoadFile] 📝 prepareJobData()', $data);
        $this->dispatchResourceJob($data);
        Log::info('[FilterLoadFile] 🚀 dispatchResourceJob() done');

        // Notify the user
        $this->notifySuccess(
            'Processing started',
            $data['dryRun'] ? 'Previewing filtered counts...' : 'Filtering in progress...'
        );
    }

    /**
     * Livewire hook — fires when the upload field changes.
     * Resets all filter state so stale data from a previous file doesn't persist.
     */
    public function updatedUpload(): void
    {
        Log::debug('[FilterLoadFile] 📤 updatedUpload()', ['upload' => $this->upload]);
        $this->resetAvailableData();
    }

    protected function resetAvailableData(): void
    {
        $this->availableRegionIds = [];
        $this->availableResourceIds = [];
        $this->availableActivityIds = [];
        $this->availableActivityTypes = [];
        $this->activityTypeCounts = [];

        // Also reset selected filters
        $this->regionIds = [];
        $this->resourceIds = [];
        $this->activityIds = [];
        $this->activityTypeIds = [];

        // Reset preview + dryRun toggle
        $this->preview = [];
        $this->dryRun = true;
    }

    private function cleanupPreviousJob(): void
    {
        $this->downloadUrl = null;
        $this->preview = [];
        $this->progress = 0;
    }

    /**
     * Extract the current form state into the array shape expected by ProcessResourceFile.
     */
    private function prepareJobData(): array
    {
        $data = $this->form->getState();

        return [
            'upload' => data_get($data, 'upload'),
            'regionIds' => data_get($data, 'regionIds', []),
            'resourceIds' => data_get($data, 'resourceIds', []),
            'activityIds' => data_get($data, 'activityIds', []),
            'activityTypeIds' => data_get($data, 'activityTypeIds', []),
            'dryRun' => data_get($data, 'dryRun', false),
            'overrideDatetime' => data_get($data, 'overrideDatetime'),
            'startDate' => data_get($data, 'startDate'),
            'endDate' => data_get($data, 'endDate'),
        ];
    }

    /**
     * Dispatch the ProcessResourceFile queued job with the prepared filter parameters.
     */
    private function dispatchResourceJob(array $data): void
    {

        Log::info('[FilterLoadFile] 📮 dispatching ProcessResourceFile', $data);

        activity()->event('FilterLoadFile.dispatch')->log('[FilterLoadFile] 📮 dispatching ProcessResourceFile'.json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        ProcessResourceFile::dispatch(
            $this->jobId,
            data_get($data, 'upload'),
            data_get($data, 'regionIds', []),
            data_get($data, 'dryRun', false),
            data_get($data, 'overrideDatetime'),
            data_get($data, 'resourceIds', []),
            data_get($data, 'activityIds', []),
            $this->startDate ? Carbon::parse($this->startDate) : null,
            $this->endDate ? Carbon::parse($this->endDate) : null,
            data_get($data, 'activityTypeIds', []),
        );
    }

    /**
     * Called by Livewire polling to track job progress.
     * Reads progress/status from cache, loads available IDs when ready,
     * and handles completion, failure, or timeout.
     */
    public function checkStatus(): void
    {
        if (! $this->jobId) {
            return;
        }

        Log::info("Polling checkStatus for jobId: {$this->jobId}");

        // Load available IDs
        $this->loadAvailableIds();

        // Track polling for debugging
        $this->incrementPollingCount();

        // Get job status data
        $this->progress = $this->getJobProgress();
        $this->status = $this->getJobStatus();

        Log::info('[FilterLoadFile] 🔄 checkStatus()', [
            'poll' => $this->pollingCount,
            'status' => $this->status,
            'progress' => $this->progress,
        ]);

        // Force progress to 100% if status is complete
        if ($this->status === 'complete' && $this->progress < 100) {
            $this->progress = 100;
        }

        // Handle job timeout
        if ($this->isJobTimedOut()) {
            $this->handleJobTimeout();

            return;
        }

        // Handle job completion
        if ($this->status === 'complete') {
            $this->handleJobCompletion();

            return;
        }

        // Handle job failure
        if ($this->status === 'failed') {
            $this->notifyDanger('Processing failed', 'Something went wrong during processing.');
            $this->resetJobState();
        }
    }

    /**
     * Reset the page for a new filter run while keeping the uploaded file.
     */
    public function resetFilterJob(): void
    {
        // Reset job-related properties
        $this->jobId = null;
        $this->progress = 0;
        $this->status = null;
        $this->downloadUrl = null;
        $this->preview = [];

        // Keep the form data if you want to allow easy reuse,
        // or reset it if you want a completely fresh start
        $this->form->fill([
            'upload' => $this->upload, // Keep the uploaded file
            'regionIds' => [],
            'resourceIds' => [],
            'activityIds' => [],
            'dryRun' => true,
        ]);

        $this->hasRunFilterJob = false;

        $this->notifySuccess('Reset complete', 'Ready to run another filter job.');
    }

    /**
     * Cancel the current in-progress job and reset UI state.
     */
    public function cancelJob(): void
    {
        if (! $this->jobId) {
            return;
        }

        // Cancel the job and clean up
        //        $this->updateCache('status', 'cancelled');
        $this->progress = 0;
        $this->status = 'cancelled';
        $this->jobId = null;

        $this->notifyWarning('Processing cancelled', 'The job has been cancelled.');
    }

    /**
     * Pull the available IDs (regions, resources, activities, activity types) from the
     * job's cache and populate the component properties that drive the filter dropdowns.
     */
    private function loadAvailableIds(): void
    {
        $availableIds = $this->getFromJobCache('available_ids', [
            'regions' => [],
            'resources' => [],
            'activities' => [],
            'activity_types' => [], // 👈 add this
            'activity_type_counts' => [], // 👈 add this to avoid issues
        ]);

        $this->availableRegionIds = $availableIds['regions'] ?? [];
        $this->availableResourceIds = $availableIds['resources'] ?? [];
        $this->availableActivityIds = $availableIds['activities'] ?? [];
        $this->availableActivityTypes = $availableIds['activity_types'] ?? [];
        $this->activityTypeCounts = $availableIds['activity_type_counts'] ?? [];

    }

    /**
     * Process a completed job: retrieve the download URL and preview data from cache,
     * toggle the form out of dry-run mode, and notify the user.
     */
    private function handleJobCompletion(): void
    {
        $this->downloadUrl = $this->getFromJobCache('download');
        $this->preview = $this->getFromJobCache('preview', []);

        $this->notifySuccess(
            'Done!',
            $this->downloadUrl ? 'File is ready to download.' : 'Preview complete.'
        );

        // Mark filter job as complete for non-dry runs
        if (! $this->dryRun) {
            $this->hasRunFilterJob = true;
        }

        // Update form state with dryRun toggled off now that data is available
        if ($this->shouldShowDropdowns()) {
            $this->dryRun = false;
        }
        $this->updateFormState();

        // Reset job state but keep form values
        $this->resetJobState();
    }

    private function updateFormState(): void
    {
        $this->form->fill([
            'upload' => $this->upload,
            'regionIds' => $this->regionIds,
            'resourceIds' => $this->resourceIds,
            'activityIds' => $this->activityIds,
            'overrideDatetime' => $this->overrideDatetime,
            'dryRun' => $this->dryRun,
        ]);
    }

    protected function getElapsedTime(): string
    {
        if (! $this->jobCreatedAt) {
            return '0:00';
        }

        $seconds = now()->diffInSeconds($this->jobCreatedAt);
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return $minutes.':'.str_pad($remainingSeconds, 2, '0', STR_PAD_LEFT);
    }
}
