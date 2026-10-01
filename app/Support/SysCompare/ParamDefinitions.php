<?php

namespace App\Support\SysCompare;

use JsonException;

/**
 * Plain-English parameter definitions: exact parameter_id lookup (case-insensitive),
 * then name-pattern fallbacks. User-supplied definitions override the built-in ones.
 */
class ParamDefinitions
{
    public const string USER_BASIS = 'User-supplied';

    /** @var array<string, ParamDefinition> keyed by lower-cased parameter name */
    private array $definitions = [];

    /** @var list<array{pattern: string, definition: ParamDefinition}> */
    private array $patterns = [];

    /**
     * @param  array<string, array{text: string, basis: string}>  $definitions
     * @param  list<array{pattern: string, text: string, basis: string}>  $patterns
     */
    public function __construct(array $definitions = [], array $patterns = [])
    {
        foreach ($definitions as $parameter => $definition) {
            $this->definitions[strtolower($parameter)] = new ParamDefinition($definition['text'], $definition['basis']);
        }

        foreach ($patterns as $pattern) {
            $this->patterns[] = [
                'pattern' => '/'.str_replace('/', '\/', $pattern['pattern']).'/i',
                'definition' => new ParamDefinition($pattern['text'], $pattern['basis']),
            ];
        }
    }

    /**
     * The definitions shipped with the app (Data/param_definitions.json).
     */
    public static function builtIn(): static
    {
        $path = __DIR__.'/Data/param_definitions.json';

        try {
            /** @var array{definitions: array<string, array{text: string, basis: string}>, patterns: list<array{pattern: string, text: string, basis: string}>} $data */
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = ['definitions' => [], 'patterns' => []];
        }

        return new static($data['definitions'], $data['patterns']);
    }

    /**
     * A copy with user definitions applied on top. Each row needs a parameter and a
     * definition; the basis defaults to "User-supplied".
     *
     * @param  list<array{Parameter?: string, Definition?: string, Basis?: string}>  $userRows
     */
    public function withUserDefinitions(array $userRows): static
    {
        $copy = clone $this;

        foreach ($userRows as $userRow) {
            $parameter = trim($userRow['Parameter'] ?? '');
            $definition = $userRow['Definition'] ?? '';

            if ($parameter === '' || trim($definition) === '') {
                continue;
            }

            $basis = trim($userRow['Basis'] ?? '') !== '' ? $userRow['Basis'] : self::USER_BASIS;
            $copy->definitions[strtolower($parameter)] = new ParamDefinition($definition, $basis);
        }

        return $copy;
    }

    /**
     * Whether the parameter has its own definition (a name-pattern hint does not count).
     */
    public function hasSpecific(string $parameter): bool
    {
        return isset($this->definitions[strtolower($parameter)]);
    }

    public function find(string $parameter): ?ParamDefinition
    {
        $specific = $this->definitions[strtolower($parameter)] ?? null;

        if ($specific !== null) {
            return $specific;
        }

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern['pattern'], $parameter) === 1) {
                return $pattern['definition'];
            }
        }

        return null;
    }
}
