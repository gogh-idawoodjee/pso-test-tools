<?php

use App\Models\ToolRun;
use App\Tools\ScriptRun;
use App\Tools\ToolContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/sr-'.uniqid();
    mkdir($this->scratch);
    $run = ToolRun::factory()->create();
    $this->context = new ToolContext($run, $run->scope, null, 'UTC');
});

afterEach(fn () => File::deleteDirectory($this->scratch));

function scriptRunMessage(ToolContext $context, string $workDir, string $code): string
{
    file_put_contents($workDir.'/s.py', $code);
    try {
        ScriptRun::execute($context, $workDir, ['python3', '-I', $workDir.'/s.py'], 30, 'Plan travels');
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    throw new LogicException('expected the script to fail');
}

it('never logs or shows a warning line, which can name resources', function () {
    $logged = [];
    Log::shouldReceive('warning')->andReturnUsing(static function (string $message, array $context = []) use (&$logged): void {
        $logged[] = $message.json_encode($context);
    });

    $message = scriptRunMessage($this->context, $this->scratch, "import sys\nsys.stderr.write('Traceback line\\nwarning: resource 1001, shift S7, activity A9 left out\\n')\nsys.exit(1)\n");

    expect($message)->toBe('Plan travels stopped while starting: Traceback line')
        ->and($logged)->toHaveCount(1)
        ->and(implode("\n", $logged))->not->toContain('1001');
});

it('names the exit code when only warning lines were written', function () {
    Log::shouldReceive('warning');

    $message = scriptRunMessage($this->context, $this->scratch, "import sys\nsys.stderr.write('warning: resource 1001 left out\\n')\nsys.exit(3)\n");

    expect($message)->toBe('Plan travels stopped while starting: exit code 3');
});

it('passes every kept stderr line through the redact callback before it is logged or shown', function () {
    $logged = [];
    Log::shouldReceive('warning')->andReturnUsing(static function (string $message, array $context = []) use (&$logged): void {
        $logged[] = $message.json_encode($context);
    });
    file_put_contents($this->scratch.'/s.py', "import sys\nsys.stderr.write('Stopped during: reading Acme North: Acme North has no rows\\n')\nsys.exit(1)\n");

    try {
        ScriptRun::execute($this->context, $this->scratch, ['python3', '-I', $this->scratch.'/s.py'], 30, 'The report',
            redact: static fn (string $text): string => str_replace('Acme North', 'Run 1', $text));
        $message = null;
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toBe('The report stopped while reading Run 1: Run 1 has no rows')
        ->and($logged)->toHaveCount(1)
        ->and(implode("\n", $logged))->toContain('Run 1 has no rows')->not->toContain('Acme');
});
