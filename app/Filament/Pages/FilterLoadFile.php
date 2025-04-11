<?php

namespace App\Filament\Pages;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;
use App\Jobs\ProcessResourceFile;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Log;
use Override;

class FilterLoadFile extends Page
{

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';
    protected static ?string $activeNavigationIcon = 'heroicon-s-funnel';

    protected static string $view = 'filament.pages.filter-load-file';
    protected static ?string $navigationGroup = 'Additional Tools';


    public $upload;
    public $regionIds = '';
    public $jobId = null;
    public $downloadUrl = null;
    public $progress = 0;

    public function mount(): void
    {
        $this->form->fill([
            'upload' => null,
            'regionIds' => '',
        ]);
    }

    #[Override] protected function getFormSchema(): array
    {
        return [
            Forms\Components\FileUpload::make('upload')
                ->label('Upload JSON File')
                ->disk('local')
                ->directory('uploads')
                ->acceptedFileTypes(['application/json'])
                ->required(),

            Forms\Components\TextInput::make('regionIds')
                ->label('Region IDs (comma-separated)')
                ->helperText('e.g. REG1, NORTH, DISTRICT2')
                ->required(),
        ];
    }

    public function submit(): void
    {
        $data = $this->form->getState();
        $this->jobId = (string)Str::uuid();

        cache()->put("resource-job:{$this->jobId}:status", 'pending');
        cache()->put("resource-job:{$this->jobId}:progress", 0);

        ProcessResourceFile::dispatch(
            $this->jobId,
            $data['upload'], // path relative to local disk
            $data['regionIds']
        );

        Notification::make()
            ->title('Processing started')
            ->body('Your file is being filtered. Please wait...')
            ->success()
            ->send();
    }

    public function checkStatus()
    {
        Log::info("Polling for job status [{$this->jobId}]");
        Log::info("Polling checkStatus for jobId: {$this->jobId}");
        if (!$this->jobId) {
            return;
        }

//        $status = cache()->get("resource-job:{$this->jobId}:status", 'pending');
        $status = Cache::get("resource-job:{$this->jobId}:status", 'pending');
//        $this->progress = cache()->get("resource-job:{$this->jobId}:progress", 0);
        $this->progress = Cache::get("resource-job:{$this->jobId}:progress", 0);
        Log::info("Job status: {$status}, progress: {$this->progress}");

        if ($status === 'complete') {
            $filename = Cache::get("resource-job:{$this->jobId}:file");
            $this->downloadUrl = route('download', compact('filename'));


            Notification::make()
                ->title('Processing complete')
                ->body('Your filtered file is ready to download.')
                ->success()
                ->send();

            cache()->forget("resource-job:{$this->jobId}:status");
            cache()->forget("resource-job:{$this->jobId}:progress");
            cache()->forget("resource-job:{$this->jobId}:file");
        }

        if ($status === 'failed') {
            Notification::make()
                ->title('Processing failed')
                ->body('Something went wrong during file processing.')
                ->danger()
                ->send();
        }
    }
}
