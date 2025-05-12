<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class DebugFilamentTheme extends Command
{
    protected $signature = 'app:debug-filament-theme';
    protected $description = 'Show which theme path Filament is using for asset compilation';

    public function handle(): void
    {
        $themePath = Config::get('filament.theme.path');

        if (! $themePath) {
            $this->error('❌ No custom theme path configured in config/filament.php.');
            return;
        }

        $this->info("🔎 Configured theme path: {$themePath}");

        if (file_exists($themePath)) {
            $this->info("✅ Theme file exists!");
        } else {
            $this->error("❌ Theme file does NOT exist at: {$themePath}");
        }
    }

}
