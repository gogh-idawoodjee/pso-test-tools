<?php

namespace App\Filament\Pages;

use App\Jobs\GetTechnicianShiftsJob;
use App\Jobs\GetTechniciansListJob;
use App\Traits\FilamentJobMonitoring;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use UnitEnum;

class TechnicianAvail extends Page
{
    use FilamentJobMonitoring;

    // Job types
    private const string JOB_TYPE_RESOURCES = 'resource-job';

    private const string JOB_TYPE_SHIFTS = 'Technician-Shift-Job';

    // 1. Constants/Static properties
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected string $view = 'filament.pages.technician-avail';

    protected static string|UnitEnum|null $navigationGroup = 'Additional Tools';

    protected static ?string $title = 'Technician Availability';

    // 2. Public properties
    public array $technicianOptions = [];

    public ?array $technicianShifts = [];

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

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make()
                ->schema([
                    FileUpload::make('upload')
                        ->label('Upload JSON File')
                        ->disk('r2')
                        ->directory('uploads')
                        ->acceptedFileTypes(['application/json'])
                        ->required(fn () => empty($this->technicianShifts))
                        ->dehydrated()
                        ->live()
                        ->columnSpan(2),

                    Select::make('selectedTechnician')
                        ->label('Select Technician')
                        ->options($this->technicianOptions)
                        ->placeholder('Choose a tech')
                        ->searchable()
                        ->afterStateUpdated(static fn ($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                        ->visible(fn () => ! empty($this->technicianOptions))
                        ->live(),
                    DatePicker::make('startDate')
                        ->label('Start Date')
                        ->default(now()->toDateString())
                        ->visible(fn () => ! empty($this->technicianOptions))
                        ->required()
                        ->native(false),
                ])->columns(),
            Actions::make([
                Action::make('get_resources')
                    ->label('Load Resources')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->disabled(fn () => ! empty($this->formData['selectedTechnician']) || empty($this->formData['upload']))
                    ->action(function () {
                        $this->getResources();
                    }),
                Action::make('get_schedule')
                    ->label('Get Technician Schedule')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->disabled(fn () => empty($this->formData['selectedTechnician']))
                    ->action(function () {
                        $this->getSchedule();
                    }),
                Action::make('load_next_batch')
                    ->label('Load Next Batch')
                    ->icon('heroicon-o-arrow-right')
                    ->action('loadNextBatch')
                    ->visible(fn () => ! empty($this->technicianShifts) && ! empty($this->formData['selectedTechnician'])),
            ]),
        ])->statePath('formData');
    }

    public function loadNextBatch(): void
    {
        $lastShift = collect($this->technicianShifts)
            ->pluck('start_datetime')
            ->map(static fn ($dt) => Carbon::parse($dt))
            ->max();

        if (! $lastShift) {
            $this->notifyWarning('No shifts loaded', 'Cannot determine next batch — no shifts available.');

            return;
        }

        $nextStart = $lastShift->copy()->addDay()->toDateString();

        $this->formData['startDate'] = $nextStart;

        $this->getSchedule();
    }

    public function getSchedule(): void
    {
        if (! $data = $this->form->getState()) {
            return;
        }

        $this->startJob(self::JOB_TYPE_SHIFTS);

        $path = $data['upload'];
        $technicianId = $data['selectedTechnician'];
        $startDate = $data['startDate'];
        Log::info("Dispatching shift job from {$startDate}");
        Log::info("Get Schedule Job ID: {$this->jobId}, File: {$path}, Technician: {$technicianId}");

        GetTechnicianShiftsJob::dispatch($this->jobId, $path, $technicianId, $startDate);
    }

    public function getResources(): void
    {
        if (! $data = $this->form->getState()) {
            return;
        }

        $this->startJob(self::JOB_TYPE_RESOURCES);

        $path = $data['upload'];
        Log::info("Job ID: {$this->jobId}, File: {$path}");

        GetTechniciansListJob::dispatch($this->jobId, $path);
    }

    public function checkStatus(): void
    {
        if (! $this->jobId || ! $this->jobKey) {
            return;
        }

        // Track polling count for debugging
        $this->incrementPollingCount();

        // Get job status data
        $this->progress = $this->getJobProgress();
        $this->status = $this->getJobStatus();

        Log::info("Polling checkStatus for jobId: {$this->jobId}, Progress: {$this->progress}, Status: {$this->status}");

        if ($this->status === 'complete') {
            Log::info('Status Changed to Completed: TechAvail checkstatus method');
            $this->handleJobCompletion();

            return;
        }

        if ($this->status === 'failed' || $this->status === 'error') {
            $this->notifyDanger('Processing failed', 'Something went wrong during processing.');
            $this->resetJobState();

            return;
        }

        if ($this->isJobTimedOut()) {
            $this->handleJobTimeout();
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

            Log::info('Technician dropdown updated with '.count($this->technicianOptions).' options');
        }
    }

    private function loadSampleData(): void
    {
        $path = base_path('tests/Fixtures/sample-technician-shifts.json');
        $this->technicianShifts = json_decode(file_get_contents($path), true);
    }
}
