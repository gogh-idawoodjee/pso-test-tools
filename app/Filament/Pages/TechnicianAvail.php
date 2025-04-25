<?php

namespace App\Filament\Pages;

use App\Jobs\GetTechnicianShiftsJob;
use App\Jobs\GetTechniciansListJob;
use App\Traits\FilamentJobMonitoring;
use Carbon\Carbon;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Override;

class TechnicianAvail extends Page
{
    use InteractsWithForms, FilamentJobMonitoring;

    // Job types
    private const string JOB_TYPE_RESOURCES = 'resource-job';
    private const string JOB_TYPE_SHIFTS = 'Technician-Shift-Job';

    // 1. Constants/Static properties
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static string $view = 'filament.pages.technician-avail';
    protected static ?string $navigationGroup = 'Additional Tools';
    protected static ?string $title = 'Technician Availability';
    // 2. Public properties
    public array $technicianOptions = [];
    public array|null $technicianShifts = [];
    #[Url]
    public bool $enableDebug = false;

    // 3. Form-related properties
    public array $formData = [
        'upload' => null,
        'selectedTechnician' => null,
        'startDate' => null,
    ];

    public function mount(): void
    {
        $this->form->fill();
        // Load sample data for development/testing
        if ($this->enableDebug) {
            $this->loadSampleData();
        }

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
                        ->acceptedFileTypes(['application/json'])
                        ->required(fn() => empty($this->technicianShifts))
                        ->dehydrated()
                        ->live()
                        ->columnSpan(2),

                    Select::make('selectedTechnician')
                        ->label('Select Technician')
                        ->options($this->technicianOptions)
                        ->placeholder('Choose a tech')
                        ->searchable()
                        ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                        ->visible(fn() => !empty($this->technicianOptions))
                        ->live(),
                    DatePicker::make('startDate')
                        ->label('Start Date')
                        ->default(now()->toDateString())
                        ->visible(fn() => !empty($this->technicianOptions))
                        ->required()
                        ->native(false),
                ])->columns(),
            Actions::make([
                Action::make('get_resources')
                    ->label('Load Resources')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->disabled(fn() => !empty($this->formData['selectedTechnician']) || empty($this->formData['upload']))
                    ->action(function () {
                        $this->getResources();
                    }),
                Action::make('get_schedule')
                    ->label('Get Technician Schedule')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->disabled(fn() => empty($this->formData['selectedTechnician']))
                    ->action(function () {
                        $this->getSchedule();
                    }),
                Action::make('load_next_batch')
                    ->label('Load Next Batch')
                    ->icon('heroicon-o-arrow-right')
                    ->action('loadNextBatch')
                    ->visible(fn() => !empty($this->technicianShifts) && !empty($this->formData['selectedTechnician'])),
            ])
        ])->statePath('formData');
    }


    public function loadNextBatch(): void
    {
        // 1) Determine the last shift date we just fetched:
        $lastShift = collect($this->technicianShifts)
            ->pluck('start_datetime')
            ->map(static fn($dt) => Carbon::parse($dt))
            ->max();

        // 2) Bump it forward one day:
        $nextStart = $lastShift->addDay()->toDateString();

        // 3) Update formData and re-fetch:
        $this->formData['startDate'] = $nextStart;

        $this->getSchedule();
    }

    public function getSchedule(): void
    {
        $this->startJob(self::JOB_TYPE_SHIFTS);

        if ($data = $this->form->getState()) {
            $path = $data['upload'];
            $technicianId = $data['selectedTechnician'];
            $startDate = $data['startDate'];
            Log::info("Dispatching shift job from {$startDate}");
            Log::info("Get Schedule Job ID: {$this->jobId}, File: {$path}, Technician: {$technicianId}");

            GetTechnicianShiftsJob::dispatch($this->jobId, $path, $technicianId, $startDate);
        }
    }

    public function getResources(): void
    {
        $this->startJob(self::JOB_TYPE_RESOURCES);

        if ($data = $this->form->getState()) {
            $path = $data['upload'];

            Log::info("Job ID: {$this->jobId}, File: {$path}");

            GetTechniciansListJob::dispatch($this->jobId, $path);
        }
    }

    public function checkStatus(): void
    {
        if (!$this->jobId || !$this->jobKey) {
            return;
        }

        // Track polling count for debugging
        $this->incrementPollingCount();

        // Get job status data
        $this->progress = $this->getJobProgress();
        $this->status = $this->getJobStatus();
        $this->data = $this->getJobData();

        Log::info("Polling checkStatus for jobId: {$this->jobId}, Progress: {$this->progress}, Status: {$this->status}");

        if ($this->status === 'complete') {
            Log::info("Status Changed to Completed: TechAvail checkstatus method");
            $this->handleJobCompletion();
        }
    }

    protected function handleJobCompletion(): void
    {
        Log::info("Job complete: {$this->jobKey}");

        // Handle different job types
        if ($this->jobKey === self::JOB_TYPE_SHIFTS) {
            $this->technicianShifts = $this->getFromJobCache('shifts', []);
        } elseif ($this->jobKey === self::JOB_TYPE_RESOURCES) {
            $technicians = $this->getFromJobCache('technicians', []);
            $this->updateTechnicianOptions($technicians);
        }

        // Notify the user
        $this->notifySuccess('Done!', 'Job completed successfully');

        // Reset job state
        $this->resetJobState();
    }


    protected function resetJobState(): void
    {
        // Only clear the job‐monitoring fields:
        $this->jobId = null;
        $this->jobKey = null;
        $this->status = null;
        $this->progress = 0;

        // NB: do NOT touch $this->formData or $this->technicianShifts here!
    }

    protected function updateTechnicianOptions(array $technicians): void
    {
        if (count($technicians) > 0) {
            $this->technicianOptions = collect($technicians)
                ->pluck('name', 'id')
                ->toArray();

            Log::info('Technician dropdown updated with ' . count($this->technicianOptions) . ' options');
        }
    }

    private function loadSampleData(): void
    {
        // Sample technician shifts data (for development/testing)
        $this->technicianShifts = [
            [
                'id' => '372729',
                'resource_id' => '155',
                'start_datetime' => '2025-04-14T12:00:00+00:00',
                'end_datetime' => '2025-04-14T21:00:00+00:00',
                'manual_scheduling_only' => false,
                'label' => 'Shift',
                'region_availability' => [
                    [
                        'id' => '155-MAINTENANCE',
                        'region_id' => 'MAINTENANCE',
                        'region_description' => 'Maintenance Zone',
                        'region_group_id' => 'SERVICE_ZONES',
                        'region_group_description' => 'Service Zones',
                        'region_active' => false,
                        'start' => '2025-04-14T12:00:00+00:00',
                        'end' => '2025-04-14T21:00:00+00:00',
                        'full_coverage' => true,
                    ],
                    [
                        'id' => '155-MAINTENANCE_AC',
                        'region_id' => 'MAINTENANCE_AC',
                        'region_description' => 'Air Conditioning',
                        'region_group_id' => 'SERVICE_ZONES',
                        'region_group_description' => 'Service Zones',
                        'region_active' => true,
                        'start' => '2025-04-14T14:00:00+00:00',
                        'end' => '2025-04-14T16:00:00+00:00',
                        'full_coverage' => false,
                    ],
                    [
                        'id' => '155-WEST',
                        'region_id' => 'WEST',
                        'region_description' => 'Western Coverage',
                        'region_group_id' => 'TERRITORIES',
                        'region_group_description' => 'Franchise Territories',
                        'region_active' => false,
                        'start' => '2025-04-14T12:00:00+00:00',
                        'end' => '2025-04-14T14:00:00+00:00',
                        'full_coverage' => false,
                    ]
                ],
                'breaks' => [
                    [
                        'start' => '2025-04-14T16:00:00+00:00',
                        'end' => '2025-04-14T17:00:00+00:00',
                    ]
                ]
            ],
            [
                'id' => '372730',
                'resource_id' => '155',
                'start_datetime' => '2025-04-15T09:00:00+00:00',
                'end_datetime' => '2025-04-15T18:00:00+00:00',
                'manual_scheduling_only' => true,
                'label' => 'Manual Shift',
                'region_availability' => [
                    [
                        'id' => '155-WEST',
                        'region_id' => 'WEST',
                        'region_description' => 'Western Coverage',
                        'region_group_id' => 'TERRITORIES',
                        'region_group_description' => 'Franchise Territories',
                        'region_active' => true,
                        'start' => '2025-04-15T09:30:00+00:00',
                        'end' => '2025-04-15T14:30:00+00:00',
                        'full_coverage' => false,
                    ],
                    [
                        'id' => '156-WEST',
                        'region_id' => 'WEST',
                        'region_description' => 'Western Coverage',
                        'region_group_id' => 'TERRITORIES',
                        'region_group_description' => 'Franchise Territories',
                        'region_active' => false,
                        'start' => '2025-04-15T14:30:00+00:00',
                        'end' => '2025-04-15T16:30:00+00:00',
                        'full_coverage' => false,
                    ]
                ],
            ],
            [
                'id' => '372731',
                'resource_id' => '155',
                'start_datetime' => '2025-04-16T07:30:00+00:00',
                'end_datetime' => '2025-04-16T16:00:00+00:00',
                'manual_scheduling_only' => false,
                'label' => 'Shift',
                'region_availability' => [
                    [
                        'id' => '155-NORTH',
                        'region_id' => 'NORTH',
                        'region_description' => 'Northern Sector',
                        'region_group_id' => 'TERRITORIES',
                        'region_group_description' => 'Franchise Territories',
                        'region_active' => false,
                        'start' => '2025-04-16T08:00:00+00:00',
                        'end' => '2025-04-16T16:00:00+00:00',
                        'full_coverage' => true,
                    ]
                ],
            ],
        ];
    }

}
