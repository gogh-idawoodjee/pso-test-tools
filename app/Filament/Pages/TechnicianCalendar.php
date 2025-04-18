<?php

namespace App\Filament\Pages;

use App\Jobs\ProcessTechnicianAvailabilityJob;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Log;


class TechnicianCalendar extends Page
{

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $activeNavigationIcon = 'heroicon-s-calendar';

    protected static string $view = 'filament.pages.technician-calendar';
    protected static ?string $navigationGroup = 'Additional Tools';


    public $upload;
    public ?Carbon $jobCreatedAt = null;
    public $jobId = null;
    public $downloadUrl = null;
    public $progress = 0;
    public $preview = [];

    public function mount(): void
    {
        $this->form->fill([
            'upload' => null,
        ]);
    }

    #[\Override] public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([Forms\Components\FileUpload::make('upload')
                        ->label('Upload JSON File')
                        ->disk('local')
                        ->directory('uploads')
                        ->maxSize(102400) // ← 100MB in kilobytes
                        ->acceptedFileTypes(['application/json'])
                        ->required(),
                    ]),
            ]);


    }

    public function submit(): void
    {

        if ($this->jobId) {
            Cache::forget("resource-job:{$this->jobId}:status");
            Cache::forget("resource-job:{$this->jobId}:progress");
            Cache::forget("resource-job:{$this->jobId}:preview");
            Cache::forget("resource-job:{$this->jobId}:download");
            $this->downloadUrl = null;
            $this->preview = [];
            $this->progress = 0;
        }

        $data = $this->form->getState();
        $this->jobId = (string)Str::uuid();
        Log::info("Job ID: {$this->jobId}, File: " . $data['upload']);

        Cache::put("resource-job:{$this->jobId}:status", 'pending');
        Cache::put("resource-job:{$this->jobId}:progress", 0);

        $this->jobCreatedAt = Carbon::now();

        ProcessTechnicianAvailabilityJob::dispatch(
            $this->jobId,
            $data['upload']

        );

        Notification::make()
            ->title('Processing started')
            ->body('Filtering in progress...')
            ->success()
            ->send();
    }


    public function checkStatus(): void
    {

        Log::info("Polling checkStatus for jobId: {$this->jobId}");
        if (!$this->jobId) {
            return;
        }

        $this->progress = Cache::get("resource-job:{$this->jobId}:progress", 0);
        Log::info("Live progress: {$this->progress}");
        $status = Cache::get("resource-job:{$this->jobId}:status");

        if ($status === 'pending' && now()->diffInSeconds($this->jobCreatedAt) > 60) {
            $this->progress = 100;
            Notification::make()->title('Job timed out.')->warning()->send();
            return;
        }

        if ($status === 'complete') {
            $this->downloadUrl = Cache::get("resource-job:{$this->jobId}:download");
            $this->preview = Cache::get("resource-job:{$this->jobId}:preview", []);


            Notification::make()
                ->title('Done!')
                ->body($this->downloadUrl ? 'File is ready to download.' : 'Preview complete.')
                ->success()
                ->send();

            cache()->forget("resource-job:{$this->jobId}:status");
            cache()->forget("resource-job:{$this->jobId}:progress");
            cache()->forget("resource-job:{$this->jobId}:file");
        }

        if ($status === 'failed') {
            Notification::make()
                ->title('Processing failed')
                ->body('Something went wrong during processing.')
                ->danger()
                ->send();
        }
    }
}
