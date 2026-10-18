<?php

namespace Tests\Support;

use App\Enums\Tool as ToolId;
use App\Support\Tools\UnusableRunInputException;
use App\Tools\Contracts\KeepsInputsPrivate;
use App\Tools\Contracts\TakesRunInputs;
use App\Tools\Contracts\Tool;
use App\Tools\Contracts\ToolResult;
use App\Tools\Contracts\WizardOnly;
use App\Tools\ToolContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A tool that takes other runs as inputs, for the StartToolRun tests: refuses fewer than two, keeps run_id and any
 * label, and keeps the title option and the labels private (encrypted).
 */
class FakeRunInputTool implements KeepsInputsPrivate, TakesRunInputs, Tool, WizardOnly
{
    /** @var list<array<string, mixed>>|null what checkRunInputs() was given last */
    public static ?array $seen = null;

    public static function id(): ToolId
    {
        return ToolId::TravelTuning;
    }

    public function requiredKinds(): array
    {
        return [];
    }

    public function optionalKinds(): array
    {
        return [];
    }

    public function optionFields(): array
    {
        return [];
    }

    public function needs(): string
    {
        return 'Needs two or more test runs';
    }

    public function privateOptionKeys(): array
    {
        return ['title'];
    }

    public function privateRunKeys(): array
    {
        return ['label'];
    }

    public function checkRunInputs(Model $scope, array $picked): array
    {
        self::$seen = $picked;
        if (count($picked) < 2) {
            throw new UnusableRunInputException(0, 'Pick at least two runs.');
        }

        return array_map(static fn (array $row): array => [
            'run_id' => (string) $row['run_id'],
            ...(isset($row['label']) ? ['label' => (string) $row['label']] : []),
        ], $picked);
    }

    public function run(ToolContext $context, array $inputPaths, array $options): ToolResult
    {
        return new ToolResult(['html' => '<p>ok</p>']);
    }
}
