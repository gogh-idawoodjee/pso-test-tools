<?php

namespace App\Tools;

use App\Models\ToolRun;
use App\Support\DatasetSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ToolContext
{
    private ?string $lastStage = null;

    private ?Carbon $lastSavedAt = null;

    public function __construct(
        public ToolRun $run,
        public Model $scope,
        public ?DatasetSettings $settings,
        public string $timeZone,
    ) {}

    /**
     * Show the user where a run is. Saved at once when the stage (or, without one, the label) changes,
     * otherwise at most every 2 seconds, so a chatty tool does not hammer the database. RunToolJob
     * clears it when the run ends.
     */
    public function reportProgress(string $label, ?int $percent = null, ?int $secondsLeft = null, ?string $stage = null): void
    {
        $now = Carbon::now();
        $currentStage = $stage ?? $label;
        if ($currentStage === $this->lastStage && $this->lastSavedAt !== null && $now->diffInSeconds($this->lastSavedAt, true) < 2) {
            return;
        }

        $this->run->forceFill(['progress' => ['label' => $label, 'percent' => $percent, 'seconds_left' => $secondsLeft]])->save();
        $this->lastStage = $currentStage;
        $this->lastSavedAt = $now;
    }
}
