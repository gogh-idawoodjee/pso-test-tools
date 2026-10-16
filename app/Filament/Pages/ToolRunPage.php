<?php

namespace App\Filament\Pages;

use App\Enums\ToolRunStatus;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Tools\StartToolRun;
use App\Support\Tools\UnusableInputException;
use App\Support\Tools\WeekLink;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Override;

/**
 * The dashboard of one tool run, reached from a dataset's or an environment's Runs tab, My files
 * (Unfiled, at unfiled/me) or the recent runs. The ToolRun global scope means another user's run is a 404.
 */
class ToolRunPage extends Page
{
    protected static ?string $slug = '{scope}/{scopeId}/runs/{run}';

    protected string $view = 'filament.pages.tool-run';

    #[Locked]
    public string $scope = '';

    #[Locked]
    public string $scopeId = '';

    #[Locked]
    public string $run = '';

    private ?ToolRun $toolRun = null;

    public function mount(string $scope, string $scopeId, string $run): void
    {
        $this->scope = $scope;
        $this->scopeId = $scopeId;
        $this->run = $run;
        $this->toolRun = null;

        $this->getToolRun();
    }

    public static function urlFor(Model $scope, ToolRun $run): string
    {
        return static::getUrl([
            'scope' => match (true) {
                $scope instanceof User => 'unfiled',
                $scope instanceof Dataset => 'datasets',
                default => 'environments',
            },
            // Unfiled is always the signed-in user's own, so the url names no id.
            'scopeId' => $scope instanceof User ? 'me' : $scope->getRouteKey(),
            'run' => $run->getKey(),
        ]);
    }

    /** "Trace an activity: activity 823729" when the run's summary names what it traced, else the tool's label. */
    #[Override]
    public function getTitle(): string
    {
        $run = $this->getToolRun();
        $subject = self::subjectOf($run);

        return $subject === null ? $run->tool->getLabel() : $run->tool->getLabel().': '.lcfirst($subject);
    }

    /** "Activity 823729" or "Work task 446613" from the summary a trace run stores, else null. */
    public static function subjectOf(ToolRun $run): ?string
    {
        $summary = is_array($run->summary) ? $run->summary : [];
        $activityId = $summary['activity_id'] ?? null;
        $workTaskId = $summary['work_task_id'] ?? null;
        if (is_scalar($activityId) && trim((string) $activityId) !== '') {
            return 'Activity '.trim((string) $activityId);
        }
        if (is_scalar($workTaskId) && trim((string) $workTaskId) !== '') {
            return 'Work task '.trim((string) $workTaskId);
        }

        return null;
    }

    public function getToolRun(): ToolRun
    {
        return $this->toolRun ??= $this->loadToolRun();
    }

    private function loadToolRun(): ToolRun
    {
        $scopeModel = match ($this->scope) {
            'datasets' => Dataset::query()->ownedByCurrentUser()->findOrFail($this->scopeId),
            'environments' => Environment::query()->where('slug', $this->scopeId)->firstOrFail(),
            'unfiled' => $this->scopeId === 'me' ? auth()->user() : abort(404),
            default => abort(404),
        };

        return ToolRun::query()
            ->where('scope_type', $scopeModel->getMorphClass())
            ->where('scope_id', (string) $scopeModel->getKey())
            ->findOrFail($this->run);
    }

    /** The page's Previous or Next week link: the same LOAD for that week, opened as its own run. */
    public function runWeek(string $week): void
    {
        $run = $this->getToolRun();
        if (($monday = WeekLink::weekFor($run, $week)) === null) {
            return;
        }
        try {
            $next = app(StartToolRun::class)->rerun($run, ['week_of' => $monday], auth()->user());
        } catch (UnusableInputException $e) {
            Notification::make()->title('The run did not start')->body($e->getMessage().' Upload the LOAD again to view another week.')->danger()->send();

            return;
        }
        $this->redirect(static::urlFor($run->scope, $next));
    }

    public function isRunning(): bool
    {
        return in_array($this->getToolRun()->status, [ToolRunStatus::Queued, ToolRunStatus::Running], true);
    }

    /**
     * The files this run used, by kind, with their names kept after the raw object is purged.
     *
     * @return Collection<string, ExportFile|null>
     */
    public function inputFiles(): Collection
    {
        $named = $this->getToolRun()->inputs['files'] ?? [];
        $files = ExportFile::query()->whereKey(array_values($named))->get()->keyBy('id');

        return collect($named)->map(static fn (string $fileId): ?ExportFile => $files->get($fileId));
    }

    public function resultView(): string
    {
        $specific = 'filament.tool-runs.'.$this->getToolRun()->tool->value;

        return view()->exists($specific) ? $specific : 'filament.tool-runs._generic';
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
