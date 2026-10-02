<?php

namespace App\Support\SysCompare;

final readonly class ParamDefinition
{
    public const string INFERRED_PREFIX = '[Inferred] ';

    /** Basis of a definition taken from the parameter catalog's official description. */
    public const string SCHEMA_REFERENCE = 'Schema reference';

    public const string NAME_PATTERN_BASIS = 'Inference (name pattern)';

    public function __construct(
        public string $text,
        public string $basis,
        public bool $userSupplied = false,
    ) {}

    /**
     * Definitions that rest only on the parameter name are not verified.
     */
    public function isInferred(): bool
    {
        return stripos($this->basis, 'Inference') === 0;
    }

    public function isKnowledgeBase(): bool
    {
        return stripos($this->basis, 'KB') === 0;
    }

    public function displayText(): string
    {
        return $this->isInferred() ? self::INFERRED_PREFIX.$this->text : $this->text;
    }
}
