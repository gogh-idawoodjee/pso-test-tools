<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Log;

class DeleteTemporaryFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string  $uploadedPath,
        public ?string $downloadedPath = null
    )
    {
    }

    public function handle(): void
    {
        if (Storage::disk('local')->exists($this->uploadedPath)) {
            Storage::disk('local')->delete($this->uploadedPath);
            Log::info("🗑️ Deleted uploaded file: {$this->uploadedPath}");
        }

        if ($this->downloadedPath && Storage::disk('public')->exists($this->downloadedPath)) {
            Storage::disk('public')->delete($this->downloadedPath);
            Log::info("🗑️ Deleted downloaded file: {$this->downloadedPath}");
        }
    }
}
