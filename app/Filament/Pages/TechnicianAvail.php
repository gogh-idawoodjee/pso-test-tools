<?php

namespace App\Filament\Pages;

use App\Jobs\GetTechnicianShiftsJob;
use App\Jobs\GetTechniciansListJob;

use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Override;

class TechnicianAvail extends Page
{

    use InteractsWithForms;

    // 1. Constants/Static properties
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static string $view = 'filament.pages.technician-avail';

    // 2. Public properties
    public ?string $jobId = null;
    public ?string $status = 'idle';
    public ?string $data = null;
    public bool $isPolling = false;
    public int $progress = 0;
    public int $countdown = 10;
    public array $technicianOptions = [];
    public array|null $technicianShifts = [];

    public string|null $jobKey = null;

    // 3. Form-related properties
    public array $formData = [
        'upload' => null,
        'selectedTechnician' => null,
    ];


    public function mount(): void
    {
        $this->form->fill();
        $this->technicianShifts = [
            [
                'id' => '101',
                'resource_id' => '2001',
                'start_datetime' => '2025-04-22T08:00:00+00:00',
                'end_datetime' => '2025-04-22T17:00:00+00:00',
                'label' => 'Shift',
                'manual_scheduling_only' => false,
                'region_availability' => [
                    [
                        'id' => 'R1',
                        'start' => '2025-04-22T08:00:00+00:00',
                        'end' => '2025-04-22T12:00:00+00:00',
                        'full_coverage' => false,
                        'region_description' => 'East Patch – Partial Override',
                    ],
                    [
                        'id' => 'R2',
                        'start' => '2025-04-22T13:00:00+00:00',
                        'end' => '2025-04-22T17:00:00+00:00',
                        'full_coverage' => false,
                        'region_description' => 'Maintenance Region',
                    ],
                ],
            ],
            [
                'id' => '102',
                'resource_id' => '2001',
                'start_datetime' => '2025-04-23T09:00:00+00:00',
                'end_datetime' => '2025-04-23T18:00:00+00:00',
                'label' => 'Shift',
                'manual_scheduling_only' => true,
                'region_availability' => [
                    [
                        'id' => 'R3',
                        'start' => '2025-04-23T09:00:00+00:00',
                        'end' => '2025-04-23T18:00:00+00:00',
                        'full_coverage' => true,
                        'region_description' => 'West Region – Full Day Coverage',
                    ],
                ],
            ],
            [
                'id' => '103',
                'resource_id' => '2001',
                'start_datetime' => '2025-04-24T12:00:00+00:00',
                'end_datetime' => '2025-04-24T20:00:00+00:00',
                'label' => 'Shift',
                'manual_scheduling_only' => false,
                'region_availability' => [],
            ],
        ];

    }

    #[Override] public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Technician Availability')
                ->schema([
                    FileUpload::make('upload')
                        ->label('Upload JSON File')
                        ->disk('local')
                        ->directory('uploads')
                        ->acceptedFileTypes(['application/json'])
                        ->required()
                        ->dehydrated() // just to be safe
                        ->live(),

                    Select::make('selectedTechnician')
                        ->label('Select Technician')
                        ->options($this->technicianOptions)
                        ->placeholder('Choose a tech')
                        ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                        ->visible(fn() => !empty($this->technicianOptions))
                        ->live(),
                ]),
            Actions::make([
                Actions\Action::make('get_resources')
                    ->label('Load Resources')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->disabled(fn() => !empty($this->formData['selectedTechnician']))
                    ->action(function () {
                        $this->getResources();
                    }),
                Actions\Action::make('get_schedule')
                    ->label('Get Technician Schedule')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->disabled(fn() => empty($this->formData['selectedTechnician']))
                    ->action(function () {
                        $this->getSchedule();
                    })
            ])
        ])->statePath('formData');
    }

    public function getSchedule(): void
    {
        $this->jobKey = "Technician-Shift-Job";
        if ($data = $this->form->getState()) {
            Log::info('valid form');

            // set the job ID
            $this->jobId = (string)Str::uuid();

            $this->status = 'starting up';
            Cache::put("Technician-Shift-Job:{$this->jobId}:status", 'starting up');
            $path = $data['upload'];
            Log::info("Get Schedule Job ID: {$this->jobId}, File: " . $path);
            Log::info("Using Technician ID: " . $data['selectedTechnician'] ?? 'none');
            Log::info("📩 Dispatching schedule job for: {$path}");
            // call the job
            Log::info("Checking status for job", [
                'jobId' => $this->jobId,
                'status' => Cache::get("Technician-Shift-Job:{$this->jobId}:status")
            ]);

            GetTechnicianShiftsJob::dispatch($this->jobId, $path, $data['selectedTechnician']);
            // polling starts when job ID is set so no need to manually start it
//            $this->togglePolling(true);

        }
    }


    public function getResources(): void
    {
        $this->jobKey = "resource-job";
        if ($data = $this->form->getState()) {

            Log::info('valid form');

            // set the job ID
            $this->jobId = (string)Str::uuid();

            $this->status = 'starting up';
            Cache::put("{$this->jobKey}:{$this->jobId}:status", 'starting up');
            $path = $data['upload'];
            Log::info("Job ID: {$this->jobId}, File: " . $path);
            Log::info("📩 Dispatching job for: {$path}");
            // call the job
            Log::info("Checking status for job", [
                'jobId' => $this->jobId,
                'status' => Cache::get("{$this->jobKey}:{$this->jobId}:status")
            ]);

            GetTechniciansListJob::dispatch($this->jobId, $path);
            // polling starts when job ID is set so no need to manually start it
//            $this->togglePolling(true);

        }

//
//        // stop the polling
//        Log::info("🛑 stopping the polling");
//        if ($this->status === 'complete') {
//            $this->togglePolling();
//        }
    }

    public function checkStatus(): void
    {

        $pollingCount = Cache::get("{$this->jobKey}:{$this->jobId}:polling-count", 0);
        Log::info("Polling count at get: {$pollingCount}");

        $pollingCount++; // Increment first
        Cache::put("{$this->jobKey}:{$this->jobId}:polling-count", $pollingCount); // Then store

        Log::info("Polling count at put: {$pollingCount}");
        Log::info("Polling checkStatus # {$pollingCount} for jobId: {$this->jobId}");


        Log::info("Polling checkStatus # {$pollingCount} for jobId: {$this->jobId}");
        $this->progress = Cache::get("{$this->jobKey}:{$this->jobId}:progress", 0);
        Log::info("Live progress: {$this->progress}");
        $this->status = Cache::get("{$this->jobKey}:{$this->jobId}:status");
        Log::info("Live Status: {$this->status}");
        $this->data = Cache::get("{$this->jobKey}:{$this->jobId}:data");

        if ($this->status === 'complete') {

            Log::info("Job complete: {$this->jobKey}.");
            $this->technicianShifts = Cache::get("{$this->jobKey}:{$this->jobId}:shifts", []);

            $technicians = Cache::get("{$this->jobKey}:{$this->jobId}:technicians", []);

            $this->technicianOptions = collect($technicians)
                ->pluck('name', 'id')
                ->toArray();
            Notification::make()
                ->title('Done!')
                ->body('finished')
                ->success()
                ->send();

            cache()->forget("{$this->jobKey}:{$this->jobId}:status");
            cache()->forget("{$this->jobKey}:{$this->jobId}:progress");

            // ✅ Keep technician options before wiping the cache
            $techs = Cache::get("{$this->jobKey}:{$this->jobId}:technicians", []);
            // better idea, only populate if $techs exists
            if (count($techs) > 0) {

                $this->technicianOptions = collect($techs)->pluck('name', 'id')->toArray();
//                Log::info('tech drop list updated: '. json_encode($this->technicianOptions[0]));
            }

            // reset some stuff
            $this->jobId = null;
            $this->status = 'idle';
            $this->progress = 0;
        }

    }

    public function togglePolling($start = false): void
    {
        Log::info("togglePolling: " . $start ? 'start' : 'stop');
        $this->isPolling = $start;

    }

}
