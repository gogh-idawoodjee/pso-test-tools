<?php

namespace App\Tools\Contracts;

/**
 * A tool whose options or source run rows carry text the user typed that can hold customer wording (Travel tuning:
 * run labels, title, client, dataset name, notes). StartToolRun stores those keys in the run's encrypted
 * private_inputs column instead of the plain inputs JSON (ToolRun::inputAttributes()); ToolRun::options() and
 * ToolRun::sourceRuns() put them back for the job, a rerun and the run page.
 */
interface KeepsInputsPrivate
{
    /** @return list<string> option keys stored encrypted */
    public function privateOptionKeys(): array;

    /** @return list<string> keys of each source run row (TakesRunInputs) stored encrypted */
    public function privateRunKeys(): array;
}
