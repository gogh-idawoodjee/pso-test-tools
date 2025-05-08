<?php

namespace App\Filament\Pages;

use App\Models\Environment;
use App\Traits\FormTrait;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;
use App\Jobs\ProcessResourceFile;
use App\Traits\FilamentJobMonitoring;
use Filament\Forms\Form;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Override;

class FilterLoadFile extends Page
{
    use InteractsWithForms, FilamentJobMonitoring, FormTrait;

    // Job type identifier
    private const string JOB_TYPE = 'resource-job';

    protected static ?string $navigationIcon = 'heroicon-o-funnel';
    protected static ?string $activeNavigationIcon = 'heroicon-s-funnel';
    protected static string $view = 'filament.pages.filter-load-file';
    protected static ?string $navigationGroup = 'Additional Tools';

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
    }


    #[Override] protected function getForms(): array
    {
        return ['env_form', 'form'];
    }


    #[Override]
    public function form(Form $form): Form
    {
        return $form->schema([
                Section::make()
                    ->schema([
                        FileUpload::make('upload')
                            ->label('Upload JSON File')
                            ->disk('local')
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
                        ])->visible(fn() => $this->shouldShowDropdowns()),


                        Toggle::make('dryRun')
                            ->label('Get Data')
                            ->helperText('Required Prior to Filtering')
                            ->disabled(fn() => !$this->shouldShowDropdowns())
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
            ->options(function () {
                return collect($this->availableRegionIds)
                    ->mapWithKeys(static function ($entry) {
                        // If the entry is like "SVBARR - Service Barrie"
                        if (str_contains($entry, ' - ')) {
                            [$id, $desc] = explode(' - ', $entry, 2);
                            return [$id => "{$id} - {$desc}"]; // 🟢 Key = ID, Value = "ID - Description"
                        }

                        return [$entry => $entry]; // fallback for malformed items
                    });
            })
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
            ->options(fn() => $this->availableResourceIds)
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
                        return array_any($this->activityTypeIds, static fn($typeId) => str_contains($label, $typeId));
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

    public function shouldShowDropdowns(): bool
    {
        return filled($this->upload) && !empty($this->availableRegionIds);
    }

    public function submit(): void
    {
        // Validate form state

        if (!$this->dryRun && (($this->startDate && !$this->endDate) || (!$this->startDate && $this->endDate))) {
            $this->notifyWarning('Date range incomplete', 'Both start and end dates must be filled or left blank.');
            return;
        }

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

    public function updatedUpload(): void
    {
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
//        $this->progress = 0;
    }

    private function prepareJobData(): array
    {
        $data = $this->form->getState();

        return [
            'upload' => data_get($data, 'upload'),
            'regionIds' => data_get($data, 'regionIds', []),
            'resourceIds' => data_get($data, 'resourceIds', []),
            'activityIds' => data_get($data, 'activityIds', []),
            'dryRun' => data_get($data, 'dryRun', false),
            'overrideDatetime' => data_get($data, 'overrideDatetime'),
            'startDate' => data_get($data, 'startDate'),
            'endDate' => data_get($data, 'endDate'),
        ];
    }

    private function dispatchResourceJob(array $data): void
    {


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

        if ($this->shouldShowDropdowns()) {
            // attempt to change state of toggle once IDs have been loaded
            $this->form->fill(['dryRun' => false]);
        }


        if ($this->status === 'complete' && !$this->dryRun) {
            // use this methdd to show the environment section and activate the push to PSO button
            $this->hasRunFilterJob = true;
        }

    }

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
