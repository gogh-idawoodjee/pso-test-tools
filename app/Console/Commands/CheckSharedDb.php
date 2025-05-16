<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckSharedDb extends Command
{
    protected $signature = 'db:check-shared';
    protected $description = 'Check shared_tokens DB connection';

    public function handle(): int
    {
        try {
            DB::connection('shared_tokens')->select('SELECT 1');
            $this->info('✅ Connection to shared_tokens successful.');
        } catch (\Exception $e) {
            $this->error('❌ Connection failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
