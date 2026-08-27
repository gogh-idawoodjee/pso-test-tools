<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CheckR2Connection extends Command
{
    protected $signature = 'storage:check-r2';

    protected $description = 'Check that the r2 disk can authenticate and list its bucket';

    public function handle(): int
    {
        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');

        $this->info("🔎 Checking r2 disk — bucket: {$bucket}, endpoint: {$endpoint}");

        try {
            $files = Storage::disk('r2')->files();
            $this->info('✅ Connection to r2 successful. '.count($files).' file(s) at bucket root.');
        } catch (\Throwable $e) {
            $this->error('❌ Connection failed: '.$e->getMessage());

            return 1;
        }

        return 0;
    }
}
