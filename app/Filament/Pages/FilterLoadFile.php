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
    public $dryRun = false;
    public $jobId = null;
    public $downloadUrl = null;
    public $progress = 0;
    public $preview = [];

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
                    ->acceptedFileTypes(['application/json'])
                    ->required(),

                    Forms\Components\TextInput::make('regionIds')
                        ->label('Region IDs to keep (comma-separated)')
                        ->helperText('e.g. REG1, NORTH, DISTRICT2. All regions except these will be removed.')
                        ->required(),
                    Forms\Components\Toggle::make('dryRun')
                        ->label('Preview Only (Dry Run)')
                        ->helperText('Get counts without creating a filtered file'),
                ]),

        ];
    }

    public function submit(): void
    {

        if (!$this->jobId) return;

        $data = $this->form->getState();
        $this->jobId = (string)Str::uuid();

        cache()->put("resource-job:{$this->jobId}:status", 'pending');
        cache()->put("resource-job:{$this->jobId}:progress", 0);

        ProcessResourceFile::dispatch(
            $this->jobId,
            $data['upload'],        // file path
            $data['regionIds'],     // region list
            $data['dryRun'] ?? false // dryRun
        );

        Notification::make()
            ->title('Processing started')
            ->body($data['dryRun'] ? 'Previewing filtered counts...' : 'Filtering in progress...')
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

        $this->progress = Cache::get("resource-job:{$this->jobId}:progress", 0);
        $status = Cache::get("resource-job:{$this->jobId}:status");

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
