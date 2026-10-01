<?php

namespace App\Console\Commands;

use App\Support\SysCompare\Storage\SysCompareStorage;
use Illuminate\Console\Command;

class PurgeSysCompareFiles extends Command
{
    protected $signature = 'sys-compare:purge';

    protected $description = 'Delete expired PSO Sys File Compare results and abandoned uploads';

    public function handle(SysCompareStorage $storage): int
    {
        $removed = $storage->purgeExpired();

        $this->info("Removed {$removed} expired item(s).");

        return self::SUCCESS;
    }
}
