<?php

namespace App\Tools;

use App\Tools\LoadAudit\ProgressLine;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One run of a pso-tools script as a child process, in the run's scratch folder, with PSO_PROGRESS=lines.
 * PROGRESS stderr lines become the run's progress; the rest of stderr is kept (last 200 lines) for the
 * failure message. Shared by every tool that runs a script; $noun ("The audit", "The viewer") names the
 * tool in the messages a user sees.
 */
final class ScriptRun
{
    /** @param list<string> $stderr */
    private function __construct(public string $stdout, public array $stderr, public string $lastLabel) {}

    /**
     * @param  list<string>  $argv
     *
     * @throws RuntimeException when the script times out or exits non-zero, in plain words
     */
    public static function execute(ToolContext $context, string $workDir, array $argv, int $timeout, string $noun): self
    {
        $lastLabel = 'starting';
        $stderr = [];
        $stdout = '';
        $pending = '';

        $handleLine = static function (string $line) use (&$lastLabel, &$stderr, $context): void {
            if ($line === '') {
                return;
            }
            $progress = ProgressLine::parse($line);
            if ($progress !== null) {
                $lastLabel = $progress['label'];
                $context->reportProgress($progress['label'], $progress['percent'], $progress['seconds_left'], $progress['stage']);
            } elseif (! str_starts_with($line, 'PROGRESS ')) {
                $stderr[] = $line;
                $stderr = array_slice($stderr, -200);
            }
        };

        try {
            $process = Process::path($workDir)
                ->timeout($timeout)
                ->env(['PSO_PROGRESS' => 'lines', 'LANG' => 'C.UTF-8'])
                ->run($argv, static function (string $type, string $buffer) use (&$stdout, &$pending, $handleLine): void {
                    if ($type === 'out') {
                        $stdout .= $buffer;

                        return;
                    }
                    // A chunk can end mid-line: only complete lines are handled, the fragment waits for the next chunk.
                    $lines = preg_split('/\r?\n/', $pending.$buffer);
                    $pending = (string) array_pop($lines);
                    foreach ($lines as $line) {
                        $handleLine($line);
                    }
                });
        } catch (ProcessTimedOutException) {
            throw new RuntimeException("{$noun} took longer than ".intdiv($timeout, 60).' minutes and was stopped.');
        }

        $handleLine($pending);

        if (! $process->successful()) {
            Log::warning('pso-tools script failed', ['tool_run_id' => $context->run->id, 'script' => $argv[2] ?? null, 'exit' => $process->exitCode(), 'stderr' => implode("\n", $stderr)]);
            $stage = strtolower(preg_replace('/:.*$/', '', $lastLabel));
            $lastLine = collect($stderr)->last(static fn (string $line): bool => trim($line) !== '') ?? 'no error text';

            throw new RuntimeException("{$noun} stopped while {$stage}: ".Str::limit(trim($lastLine), 300));
        }

        return new self($stdout, $stderr, $lastLabel);
    }
}
