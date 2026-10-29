<?php

namespace App\Tools;

use App\Tools\LoadAudit\ProgressLine;
use Closure;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One run of a pso-tools script as a child process, in the run's scratch folder, with PSO_PROGRESS=lines.
 * PROGRESS stderr lines become the run's progress; the rest of stderr is kept (last 200 lines) for the
 * failure message, except "warning: " lines, which can name resources, shifts and activities and so are
 * never logged nor shown as the failure. Shared by every tool that runs a script; $noun ("The audit", "The viewer") names the
 * tool in the messages a user sees.
 */
final class ScriptRun
{
    /** @param list<string> $stderr */
    private function __construct(public string $stdout, public array $stderr, public string $lastLabel) {}

    /**
     * @param  list<string>  $argv
     * @param  ?int  $reasonExitCode  an exit code the script uses for "nothing to show, and here is why": its last stderr line becomes the whole message
     * @param  ?string  $progressLabel  names what the script is doing in place of ProgressLine's stage label ("Building legs: 40%")
     * @param  ?Closure(string): string  $redact  applied to every stderr line kept, before it is logged or put in a message (Travel tuning swaps the run labels for Run N)
     *
     * @throws RuntimeException when the script times out or exits non-zero, in plain words
     */
    public static function execute(ToolContext $context, string $workDir, array $argv, int $timeout, string $noun, ?int $reasonExitCode = null, ?string $progressLabel = null, ?Closure $redact = null): self
    {
        $lastLabel = 'starting';
        $stderr = [];
        $stdout = '';
        $pending = '';

        $handleLine = static function (string $line) use (&$lastLabel, &$stderr, $context, $progressLabel, $redact): void {
            if ($line === '') {
                return;
            }
            $progress = ProgressLine::parse($line);
            if ($progress !== null && $progressLabel !== null) {
                $progress['label'] = $progress['percent'] === null ? $progressLabel : "{$progressLabel}: {$progress['percent']}%";
            }
            if ($progress !== null) {
                $lastLabel = $progress['label'];
                $context->reportProgress($progress['label'], $progress['percent'], $progress['seconds_left'], $progress['stage'], $progress['details']);
            } elseif (! str_starts_with($line, 'PROGRESS ') && ! str_starts_with($line, 'warning: ')) {
                $stderr[] = $redact === null ? $line : $redact($line);
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
            $lastLine = collect($stderr)->last(static fn (string $line): bool => trim($line) !== '');
            if ($reasonExitCode !== null && $process->exitCode() === $reasonExitCode) {
                throw new RuntimeException(Str::limit(trim($lastLine ?? 'no error text'), 300));
            }

            $stopped = self::stoppedDuring($stderr);
            if ($stopped !== null) {
                throw new RuntimeException("{$noun} stopped while {$stopped['stage']}: ".Str::limit($stopped['message'], 300));
            }

            $stage = strtolower(preg_replace('/:.*$/', '', $lastLabel));

            throw new RuntimeException("{$noun} stopped while {$stage}: ".Str::limit(trim($lastLine ?? 'exit code '.$process->exitCode()), 300));
        }

        return new self($stdout, $stderr, $lastLabel);
    }

    /**
     * The script's own "Stopped during" report, when it wrote one: either one line
     * "Stopped during: <stage>: <message>", or "Stopped during: <stage>" followed by "<ExceptionClass>: <message>".
     * The exception class name is dropped so the user reads a plain sentence.
     *
     * @param  list<string>  $stderr
     * @return array{stage: string, message: string}|null
     */
    private static function stoppedDuring(array $stderr): ?array
    {
        $prefix = 'Stopped during: ';
        for ($i = count($stderr) - 1; $i >= 0; $i--) {
            $line = trim($stderr[$i]);
            if (! str_starts_with($line, $prefix)) {
                continue;
            }
            $rest = substr($line, strlen($prefix));
            if (str_contains($rest, ': ')) {
                [$stage, $message] = explode(': ', $rest, 2);
            } else {
                $stage = $rest;
                $after = array_values(array_filter(array_slice($stderr, $i + 1), static fn (string $l): bool => trim($l) !== ''));
                $message = isset($after[0]) ? (string) preg_replace('/^[A-Za-z_][\\w.]*: /', '', trim($after[0])) : 'no error text';
            }

            return ['stage' => trim($stage), 'message' => trim($message) === '' ? 'no error text' : trim($message)];
        }

        return null;
    }
}
