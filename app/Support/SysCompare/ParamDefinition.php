<?php

namespace App\Support\SysCompare;

final readonly class ParamDefinition
{
    public const string INFERRED_PREFIX = '[Inferred] ';

    public function __construct(
        public string $text,
        public string $basis,
    ) {}

    /**
     * Definitions that rest only on the parameter name are not verified.
     */
    public function isInferred(): bool
    {
        return stripos($this->basis, 'Inference') === 0;
    }

    public function displayText(): string
    {
        return $this->isInferred() ? self::INFERRED_PREFIX.$this->text : $this->text;
    }
}
