<?php

namespace App\Support\SysCompare;

use JsonException;

/**
 * Plain-English parameter definitions.
 *
 * The official description in the parameter catalog IS the definition. ParamDefinitions.csv
 * adds to it: a non-empty Definition replaces the official text (parameters the catalog does
 * not cover, or an override), and a Note is appended after it as "... Note: <note>". Lookup is
 * by parameter id, case-insensitive. When neither exists, name patterns give a hint, else there
 * is no definition. A user's rows replace the built-in row for the same parameter.
 */
class ParamDefinitions
{
    public const string USER_BASIS = 'User-supplied';

    public const string BUILT_IN_DEFINITIONS = __DIR__.'/Data/ParamDefinitions.csv';

    public const string BUILT_IN_PATTERNS = __DIR__.'/Data/definition_patterns.json';

    private static ?self $builtIn = null;

    /** @var array<string, array{definition: string, note: string, basis: string}> keyed by lower-cased parameter name */
    private array $definitions = [];

    /** @var list<array{pattern: string, definition: ParamDefinition}> */
    private array $patterns = [];

    /**
     * @param  array<string, array{definition?: string, note?: string, basis?: string}>  $definitions
     * @param  list<array{pattern: string, text: string, basis: string}>  $patterns
     */
    public function __construct(array $definitions = [], array $patterns = [])
    {
        foreach ($definitions as $parameter => $row) {
            $this->definitions[strtolower((string) $parameter)] = [
                'definition' => $row['definition'] ?? '',
                'note' => $row['note'] ?? '',
                'basis' => $row['basis'] ?? '',
            ];
        }

        foreach ($patterns as $pattern) {
            $this->patterns[] = [
                'pattern' => '/'.str_replace('/', '\/', $pattern['pattern']).'/i',
                'definition' => new ParamDefinition($pattern['text'], $pattern['basis']),
            ];
        }
    }

    /**
     * The definitions shipped with the app (Data/ParamDefinitions.csv and Data/definition_patterns.json).
     */
    public static function builtIn(): static
    {
        if (static::$builtIn !== null) {
            return static::$builtIn;
        }

        $definitions = [];

        foreach (self::readBuiltInRows() as $row) {
            $parameter = trim($row['Parameter']);

            if ($parameter !== '' && (trim($row['Definition']) !== '' || trim($row['Note']) !== '')) {
                $definitions[$parameter] = ['definition' => $row['Definition'], 'note' => $row['Note'], 'basis' => $row['Basis']];
            }
        }

        try {
            /** @var array{patterns: list<array{pattern: string, text: string, basis: string}>} $patterns */
            $patterns = json_decode((string) file_get_contents(self::BUILT_IN_PATTERNS), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $patterns = ['patterns' => []];
        }

        return static::$builtIn = new static($definitions, $patterns['patterns']);
    }

    /**
     * @return list<array{Parameter: string, Definition: string, Note: string, Basis: string}>
     */
    private static function readBuiltInRows(): array
    {
        try {
            return DefinitionsCsv::parse(self::BUILT_IN_DEFINITIONS, 'ParamDefinitions.csv');
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * A copy with the user's rows applied. A row replaces the built-in row for the same
     * parameter; it needs a parameter and a Definition or a Note, and the basis defaults to
     * "User-supplied".
     *
     * @param  list<array{Parameter?: string, Definition?: string, Note?: string, Basis?: string}>  $userRows
     */
    public function withUserDefinitions(array $userRows): static
    {
        $copy = clone $this;

        foreach ($userRows as $userRow) {
            $parameter = trim($userRow['Parameter'] ?? '');
            $definition = $userRow['Definition'] ?? '';
            $note = $userRow['Note'] ?? '';

            if ($parameter === '' || (trim($definition) === '' && trim($note) === '')) {
                continue;
            }

            $copy->definitions[strtolower($parameter)] = [
                'definition' => $definition,
                'note' => $note,
                'basis' => trim($userRow['Basis'] ?? '') !== '' ? $userRow['Basis'] : self::USER_BASIS,
            ];
        }

        return $copy;
    }

    /**
     * The definition to show for a parameter:
     *  1. a Definition from ParamDefinitions.csv (plus its Note);
     *  2. the catalog's official description (plus the Note, appended);
     *  3. a name-pattern hint.
     */
    public function find(string $parameter, string $application = '', ?ParameterCatalog $catalog = null): ?ParamDefinition
    {
        $row = $this->definitions[strtolower($parameter)] ?? null;

        if ($row !== null && trim($row['definition']) !== '') {
            return new ParamDefinition($this->withNote($row['definition'], $row['note'], false), $row['basis']);
        }

        $entry = $catalog?->entry($parameter, $application);

        if ($entry !== null && trim($entry->description) !== '') {
            $hasNote = $row !== null && trim($row['note']) !== '';

            return new ParamDefinition(
                $this->withNote(trim($entry->description), $row['note'] ?? '', true),
                $hasNote ? ParamDefinition::SCHEMA_REFERENCE.' + '.$row['basis'] : ParamDefinition::SCHEMA_REFERENCE,
            );
        }

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern['pattern'], $parameter) === 1) {
                return $pattern['definition'];
            }
        }

        return null;
    }

    /**
     * Whether the parameter still needs a definition, and if so the best hint to start from
     * ('' when there is none). Null when it already has a real definition.
     */
    public function templateHint(string $parameter, string $application = '', ?ParameterCatalog $catalog = null): ?string
    {
        $definition = $this->find($parameter, $application, $catalog);

        return match (true) {
            $definition === null => '',
            $definition->basis === ParamDefinition::NAME_PATTERN_BASIS => $definition->text,
            default => null,
        };
    }

    private function withNote(string $text, string $note, bool $closeSentence): string
    {
        if (trim($note) === '') {
            return $text;
        }

        if ($closeSentence && ! str_ends_with($text, '.')) {
            $text .= '.';
        }

        return $text.' Note: '.$note;
    }
}
