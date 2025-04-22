<?php

namespace App\Filament\Pages;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;
use App\Jobs\ProcessResourceFile;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
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
    public ?Carbon $jobCreatedAt = null;
    public $regionIds = '';
    public $dryRun = false;
    public $jobId = null;
    public $downloadUrl = null;
    public $progress = 0;
    public $preview = [];
    public $overrideDatetime = null;

    public function mount(): void
    {
        $this->form->fill([
            'upload' => null,
            'regionIds' => '',
            'dryRun' => false,
        ]);
    }

    #[Override] protected function getFormSchema(): array
    {
        return [
            Forms\Components\Section::make()
                ->schema([Forms\Components\FileUpload::make('upload')
                    ->label('Upload JSON File')
                    ->disk('local')
                    ->directory('uploads')
                    ->maxSize(102400) // ← 100MB in kilobytes
                    ->acceptedFileTypes(['application/json'])
                    ->required(),

                    Forms\Components\TextInput::make('regionIds')
                        ->label('Region IDs to keep (comma-separated)')
                        ->helperText('e.g. REG1, NORTH, DISTRICT2. All regions except these will be removed.')
                        ->required(),
                    Forms\Components\DateTimePicker::make('overrideDatetime')
                        ->label('Override Input Reference Datetime')
                        ->helperText('Optional. Will replace the datetime in the input_reference block.')
                        ->nullable()
                        ->native(false),
                    Forms\Components\Toggle::make('dryRun')
                        ->label('Preview Only (Dry Run)')
                        ->helperText('Get counts without creating a filtered file'),
                ]),

        ];
    }

    public function submit(): void
    {
        Log::info("Submit clicked. Dry run: " . ($this->dryRun ? 'yes' : 'no'));

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

        ProcessResourceFile::dispatch(
            $this->jobId,
            $data['upload'],
            $data['regionIds'],
            $data['dryRun'] ?? false,
            $data['overrideDatetime'] ?? null
        );

        Notification::make()
            ->title('Processing started')
            ->body($data['dryRun'] ? 'Previewing filtered counts...' : 'Filtering in progress...')
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
