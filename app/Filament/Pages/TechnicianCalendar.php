<?php

namespace App\Filament\Pages;

use App\Jobs\GetTechniciansListJob;
use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Concerns\InteractsWithForms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;
use Log;

class TechnicianCalendar extends Page
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.technician-calendar';

    public array $formData = [
        'upload' => null,
        'selectedTechnician' => null,
    ];

    public bool $processing = false;
    public ?string $jobId = null;
    public array $technicianOptions = [];

    public function pollForCompletion(): void
    {
        for ($i = 0; $i < 30; $i++) {
            sleep(1);

            $status = Cache::get("resource-job:{$this->jobId}:status");

            if ($status === 'complete') {
                $technicians = Cache::get("resource-job:{$this->jobId}:technicians", []);
                $this->technicianOptions = collect($technicians)->pluck('name', 'id')->toArray();
                $this->formData['selectedTechnician'] = null;
                $this->processing = false;
                $this->jobId = null;

                Log::info("✅ Job complete, dropdown loaded (sync poll).");
                return;
            }

            if ($status === 'failed') {
                Log::error("❌ Job failed during sync poll.");
                $this->processing = false;
                return;
            }
        }

        Log::warning("⏱ Timeout waiting for job to complete.");
    }

    #[\Override] public function form(Form $form): Form
    {
        return $form
            ->statePath('formData')
            ->schema([
                Forms\Components\FileUpload::make('upload')
                    ->label('Upload JSON File')
                    ->disk('local')
                    ->directory('uploads')
                    ->acceptedFileTypes(['application/json'])
                    ->required()
                    ->dehydrated(true) // just to be safe
                    ->live(),

                Forms\Components\Select::make('selectedTechnician')
                    ->label('Select Technician')
                    ->options($this->technicianOptions)
                    ->placeholder('Choose a tech')
                    ->disabled(fn() => empty($this->technicianOptions)),
            ]);
    }

    public function submit(): void
    {
        $data = $this->form->getState();
        $this->jobId = (string) Str::uuid();
        $this->processing = true;

        Log::info("📩 Dispatching job for: {$data['upload']}");

        GetTechniciansListJob::dispatch($this->jobId, $data['upload']);

        // Start backend polling loop
        $this->pollForCompletion();

        Notification::make()
            ->title('Processing started')
            ->success()
            ->send();
    }


    public function checkStatus(): void
    {
        Log::info("🧪 checkStatus() called for jobId: {$this->jobId}");

        if (!$this->jobId) {
            Log::warning('🚫 checkStatus aborted — no jobId set.');
            return;
        }

        $status = Cache::get("resource-job:{$this->jobId}:status");
        Log::info("📦 Job status from cache: " . $status);

        if ($status === 'complete') {
            $technicians = Cache::get("resource-job:{$this->jobId}:technicians", []);
            Log::info("🎯 Technician list loaded: " . count($technicians));

            $this->technicianOptions = collect($technicians)
                ->pluck('name', 'id')
                ->toArray();

            $this->formData['selectedTechnician'] = null;
            $this->processing = false;
            $this->jobId = null;

            Log::info("✅ Spinner OFF, dropdown populated.");
        }

        if ($status === 'failed') {
            $this->processing = false;
            Log::error("❌ checkStatus(): job failed");
            Notification::make()
                ->title('Processing failed')
                ->danger()
                ->send();
        }
    }
}
