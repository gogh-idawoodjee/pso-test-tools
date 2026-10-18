<?php

namespace App\Tools\Contracts;

use App\Support\Tools\UnusableRunInputException;
use Illuminate\Database\Eloquent\Model;

/**
 * A tool whose inputs are other finished runs (Travel tuning reads Plan travels runs), not export files. It declares
 * no export kinds; StartToolRun stores what checkRunInputs() returns as the run's inputs['runs'], in order (keys a
 * KeepsInputsPrivate tool names go to the encrypted private_inputs instead; read the rows with ToolRun::sourceRuns()).
 */
interface TakesRunInputs
{
    /** Shown on the catalogue tile in place of the export kinds. */
    public function needs(): string;

    /**
     * The picked runs, checked against the scope and normalised. Each row carries the tool's own keys.
     *
     * @param  list<array<string, mixed>>  $picked  in order, as the wizard sent them
     * @return list<array<string, mixed>>
     *
     * @throws UnusableRunInputException
     */
    public function checkRunInputs(Model $scope, array $picked): array;
}
