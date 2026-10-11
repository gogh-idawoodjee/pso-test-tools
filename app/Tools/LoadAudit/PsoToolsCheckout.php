<?php

namespace App\Tools\LoadAudit;

use App\Tools\Contracts\ToolSetupException;
use Symfony\Component\Process\ExecutableFinder;

/** Where the pso-tools scripts and reference files live on this server. */
class PsoToolsCheckout
{
    public function __construct(private ?string $root, private string $python) {}

    public static function fromConfig(): self
    {
        $root = config('tools.pso_tools_path');

        $python = trim((string) config('tools.python', 'python3'));

        return new self(filled($root) ? rtrim((string) $root, '/') : null, $python !== '' ? $python : 'python3');
    }

    /** The configured interpreter, checked to exist so a missing one is a setup message, not a shell error. */
    public function python(): string
    {
        $found = str_contains($this->python, '/')
            ? is_file($this->python) && is_executable($this->python)
            : app(ExecutableFinder::class)->find($this->python) !== null;

        if (! $found) {
            throw new ToolSetupException("The load audit is not set up on this server: Python ({$this->python}) was not found.");
        }

        return $this->python;
    }

    public function script(): string
    {
        return $this->file('load-audit/audit_load.py');
    }

    public function paramsFile(): string
    {
        return $this->file('kb/pso_parameters_reference.csv');
    }

    public function watchlistFile(): string
    {
        return $this->file('kb/28-release-notes-watchlist.md');
    }

    private function file(string $relative): string
    {
        if ($this->root === null) {
            throw new ToolSetupException('The load audit is not set up on this server: TOOLS_PSO_TOOLS_PATH is not set.');
        }

        $path = $this->root.'/'.$relative;
        if (! is_file($path)) {
            throw new ToolSetupException("The load audit is not set up on this server: {$path} is missing.");
        }

        return $path;
    }
}
