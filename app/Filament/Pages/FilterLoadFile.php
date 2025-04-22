<?php

namespace App\Filament\Pages;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;
use App\Jobs\ProcessResourceFile;
use App\Traits\FilamentJobMonitoring;
use Filament\Forms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Override;

class FilterLoadFile extends Page
{
    use InteractsWithForms;
    use FilamentJobMonitoring;

    // Job type identifier
    private const string JOB_TYPE = 'resource-job';

    protected static ?string $navigationIcon = 'heroicon-o-funnel';
    protected static ?string $activeNavigationIcon = 'heroicon-s-funnel';
    protected static string $view = 'filament.pages.filter-load-file';
    protected static ?string $navigationGroup = 'Additional Tools';

    // File upload and processing properties
    public $upload;
    public ?Carbon $jobCreatedAt = null;
    public array $regionIds = [];
    public array $resourceIds = [];
    public array $activityIds = [];
    public bool $dryRun = true;
    public $downloadUrl = null;
    public array $preview = [];
    public array $availableActivityIds = [];
    public array $availableResourceIds = [];
    public array $availableRegionIds = [];
    public $overrideDatetime = null;

    public function mount(): void
    {
        $this->form->fill([
            'upload' => null,
            'regionIds' => [],
            'dryRun' => true,
            'resourceIds' => [],
            'activityIds' => [],
        ]);
    }

    #[Override]
    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\FileUpload::make('upload')
                        ->label('Upload JSON File')
                        ->disk('local')
                        ->directory('uploads')
                        ->maxSize(102400) // ← 100MB in kilobytes
                        ->acceptedFileTypes(['application/json'])
                        ->required(),

                    $this->createRegionSelector(),
                    $this->createResourceSelector(),
                    $this->createActivitySelector(),
                    $this->createDatetimeOverrideField(),

                    Forms\Components\Toggle::make('dryRun')
                        ->label('Preview Only (Dry Run)')
                        ->helperText('Get counts without creating a filtered file')
                        ->default(true)
                        ->live(),
                ]),
        ];
    }

    protected function createRegionSelector(): Forms\Components\Select
    {
        return Forms\Components\Select::make('regionIds')
            ->label('Regions to Keep')
            ->multiple()
            ->options(fn() => collect($this->availableRegionIds)->mapWithKeys(static fn($id) => [$id => $id]))
            ->visible(fn() => $this->shouldShowDropdowns())
            ->searchable()
            ->native(false)
            ->helperText('Only these regions will be kept. Others will be removed.');
    }

    protected function createResourceSelector(): Forms\Components\Select
    {
        return Forms\Components\Select::make('resourceIds')
            ->label('Filter to Specific Resources')
            ->multiple()
            ->options(fn() => $this->availableResourceIds)
            ->visible(fn() => $this->shouldShowDropdowns())
            ->searchable()
            ->native(false)
            ->helperText('Optional. Only these resources will be included if selected.');
    }

    protected function createActivitySelector(): Forms\Components\Select
    {
        return Forms\Components\Select::make('activityIds')
            ->label('Filter to Specific Activities')
            ->multiple()
            ->options(fn() => $this->availableActivityIds)
            ->visible(fn() => $this->shouldShowDropdowns())
            ->searchable()
            ->native(false)
            ->helperText('Optional. Only these activities will be included if selected.');
    }

    protected function createDatetimeOverrideField(): Forms\Components\DateTimePicker
    {
        return Forms\Components\DateTimePicker::make('overrideDatetime')
            ->label('Override Input Reference Datetime')
            ->prefixIcon('heroicon-o-calendar-days')
            ->visible(fn() => $this->shouldShowDropdowns())
            ->helperText('Optional. Replaces the datetime in the Input_Reference.')
            ->native(false)
            ->seconds(false)
            ->nullable();
    }

    public function shouldShowDropdowns(): bool
    {
        return filled($this->upload) && !empty($this->availableRegionIds);
    }

    public function submit(): void
    {
        // Validate form state
        if (!$this->dryRun && empty($this->availableRegionIds)) {
            $this->notifyWarning('Please preview first', 'Run a dry run to load region, resource, and activity IDs.');
            return;
        }

        Log::info("Submit clicked. Dry run: " . ($this->dryRun ? 'yes' : 'no'));

        // Start a new job
        $this->startJob(self::JOB_TYPE);

        // Save job creation time for timeout tracking
        $this->jobCreatedAt = Carbon::now();

        // Clean up previous job data if exists
        $this->cleanupPreviousJob();

        // Get form data and dispatch job
        $data = $this->prepareJobData();
        $this->dispatchResourceJob($data);

        // Notify the user
        $this->notifySuccess(
            'Processing started',
            $data['dryRun'] ? 'Previewing filtered counts...' : 'Filtering in progress...'
        );
    }

    private function cleanupPreviousJob(): void
    {
        $this->downloadUrl = null;
        $this->preview = [];
        $this->progress = 0;
    }

    private function prepareJobData(): array
    {
        $data = $this->form->getState();
        $data['regionIds'] = $data['regionIds'] ?? [];
        $data['resourceIds'] = $data['resourceIds'] ?? [];
        $data['activityIds'] = $data['activityIds'] ?? [];
        return $data;
    }

    private function dispatchResourceJob(array $data): void
    {
        ProcessResourceFile::dispatch(
            $this->jobId,
            $data['upload'],
            $data['regionIds'],
            $data['dryRun'] ?? false,
            $data['overrideDatetime'] ?? null,
            $data['resourceIds'] ?? [],
            $data['activityIds'] ?? [],
        );
    }

    public function checkStatus(): void
    {
        if (!$this->jobId) {
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


        // Check for job timeout
        if ($this->isJobTimedOut()) {
            $this->handleJobTimeout();
            return;
        }

        // Handle job completion
        if ($this->status === 'complete') {
            $this->handleJobCompletion();
        }

        // Handle job failure
        if ($this->status === 'failed') {
            $this->notifyDanger('Processing failed', 'Something went wrong during processing.');
        }
    }

    private function loadAvailableIds(): void
    {
        $availableIds = $this->getFromJobCache('available_ids', [
            'regions' => [],
            'resources' => [],
            'activities' => []
        ]);

        $this->availableRegionIds = $availableIds['regions'] ?? [];
        $this->availableResourceIds = $availableIds['resources'] ?? [];
        $this->availableActivityIds = $availableIds['activities'] ?? [];

        Log::info('Available regions: ' . count($this->availableRegionIds));
        Log::info('Available resources: ' . count($this->availableResourceIds));
        Log::info('Available activities: ' . count($this->availableActivityIds));
    }

    private function handleJobCompletion(): void
    {
        $this->downloadUrl = $this->getFromJobCache('download');
        $this->preview = $this->getFromJobCache('preview', []);

        $this->notifySuccess(
            'Done!',
            $this->downloadUrl ? 'File is ready to download.' : 'Preview complete.'
        );

        // Update form state
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
}
