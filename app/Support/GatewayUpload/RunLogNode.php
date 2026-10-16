<?php

namespace App\Support\GatewayUpload;

/**
 * One node of the run-log timeline: a step line (or the leading lines
 * before the first step) and the detail lines that follow it.
 */
final readonly class RunLogNode
{
    /**
     * @param  array<int, array{level: string, text: string, at: string}>  $details
     */
    public function __construct(
        public string $title,
        public ?string $at,
        public array $details,
        public bool $hasWarn,
        public bool $hasError,
        public bool $hasOk,
        public bool $isCurrent,
    ) {}

    /**
     * How the marker on the rail looks: an error wins, then the step still
     * running, then a warning, then a confirmed step, then a plain finished one.
     *
     * @return 'error'|'current'|'warn'|'ok'|'done'
     */
    public function state(): string
    {
        return match (true) {
            $this->hasError => 'error',
            $this->isCurrent => 'current',
            $this->hasWarn => 'warn',
            $this->hasOk => 'ok',
            default => 'done',
        };
    }
}
