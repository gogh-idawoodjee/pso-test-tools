<?php

namespace App\Support\Tools;

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\RunInputPicker;
use App\Tools\ToolRegistry;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The one way a tool run starts, from the Runs tab or a wizard: checks the chosen files are still usable on this scope (a dataset, an environment, or the user for Unfiled), creates the queued run, dispatches the job and remembers the context on the user.
 */
class StartToolRun
{
    public function __construct(private readonly ToolRegistry $registry, private readonly RunInputPicker $picker) {}

    /**
     * @param  array<string, string|null>  $files  kind value => export file id
     * @param  array<string, mixed>  $options
     *
     * @throws UnusableInputException
     */
    public function start(Model $scope, ToolId $toolId, array $files, array $options, User $user): ToolRun
    {
        if ($scope instanceof User && ($scope->isNot($user) || ! $toolId->acceptsUnfiled())) {
            throw new InvalidArgumentException("{$toolId->getLabel()} cannot run on these unfiled exports.");
        }

        $tool = $this->registry->for($toolId);
        $chosen = [];

        foreach ([...$tool->requiredKinds(), ...$tool->optionalKinds()] as $kind) {
            $fileId = $files[$kind->value] ?? null;
            if (blank($fileId)) {
                if (in_array($kind, $tool->requiredKinds(), true)) {
                    throw UnusableInputException::missing($kind);
                }

                continue;
            }
            $chosen[$kind->value] = (string) $fileId;
        }
        $this->assertUsable($scope, $toolId, $chosen);

        $run = $scope->toolRuns()->create([
            'tool' => $toolId,
            'inputs' => ['files' => $chosen, 'options' => $options],
            'started_by_user_id' => $user->id,
            'status' => ToolRunStatus::Queued,
        ]);

        RunToolJob::dispatch($run->id);

        $this->remember($scope, $options, $user);

        return $run;
    }

    /**
     * An Unfiled run keeps the environment and dataset remembered before it, so a dataset tool still opens on them.
     *
     * @param  array<string, mixed>  $options
     */
    private function remember(Model $scope, array $options, User $user): void
    {
        if ($scope instanceof User) {
            $previous = $user->last_tool_context ?? [];
            $user->rememberToolContext(
                environmentId: $previous['environment_id'] ?? null,
                datasetId: $previous['dataset_id'] ?? null,
                unfiled: true,
                timeZone: is_string($options['time_zone'] ?? null) && $options['time_zone'] !== '' ? $options['time_zone'] : null,
            );

            return;
        }

        $user->rememberToolContext(
            environmentId: $scope instanceof Dataset ? $scope->environment_id : (string) $scope->getKey(),
            datasetId: $scope instanceof Dataset ? $scope->getKey() : null,
            timeZone: $user->last_tool_context['time_zone'] ?? null,
        );
    }

    /**
     * The same run again on the same scope and files, with some options changed (the viewer's
     * Previous and Next week links). A file that has since been purged is refused like any other.
     *
     * @param  array<string, mixed>  $options  option keys to change; the rest come from the run
     *
     * @throws UnusableInputException
     */
    public function rerun(ToolRun $run, array $options, User $user): ToolRun
    {
        $inputs = $run->inputs ?? [];

        return $this->start($run->scope, $run->tool, $inputs['files'] ?? [], [...($inputs['options'] ?? []), ...$options], $user);
    }

    /**
     * Every chosen file must still be usable on this scope. Blank choices and keys the tool
     * does not take are ignored; a missing required file is the caller's to report.
     *
     * @param  array<string, string|null>  $files  kind value => export file id
     *
     * @throws UnusableInputException
     */
    public function assertUsable(Model $scope, ToolId $toolId, array $files): void
    {
        $usable = $this->picker->optionsFor($scope, $toolId);

        foreach ($this->picker->kindsOf($toolId) as $kind) {
            $fileId = $files[$kind->value] ?? null;
            if (filled($fileId) && ! array_key_exists((string) $fileId, $usable[$kind->value] ?? [])) {
                throw UnusableInputException::unavailable($kind);
            }
        }
    }
}
