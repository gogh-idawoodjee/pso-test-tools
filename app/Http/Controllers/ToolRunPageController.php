<?php

namespace App\Http\Controllers;

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Tools\EmbeddedPageData;
use App\Support\Tools\ToolRunFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The page a tool run rendered (the pso-tools script's own HTML) and its downloads, for any run whose
 * result holds `html`. The page carries text from customer files and builds itself with JavaScript, so
 * it is served under a CSP sandbox and never with the app's origin.
 */
class ToolRunPageController extends Controller
{
    public const SANDBOX = 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation';

    /** Plan travels links to the app's own Travel Analyzer: the new tab must leave the sandbox to use the session. */
    public const SANDBOX_WITH_APP_LINKS = self::SANDBOX.' allow-popups-to-escape-sandbox';

    public function page(string $scopeType, string $scopeId, string $run): Response
    {
        [, $toolRun] = $this->find($scopeType, $scopeId, $run);

        return response($toolRun->result['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => $toolRun->tool === ToolId::PlanTravels ? self::SANDBOX_WITH_APP_LINKS : self::SANDBOX,
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function downloadHtml(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        [$scope, $toolRun] = $this->find($scopeType, $scopeId, $run);

        return response()->streamDownload(static function () use ($toolRun): void {
            echo $toolRun->result['html'];
        }, self::fileName($scope, $toolRun, 'html'), ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * The run's data file: the viewer's gathered JSON (`data`), the audit's findings (`findings`), or for a
     * run that stored only its page (Plan travels) the data the page embeds.
     */
    public function downloadJson(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        [$scope, $toolRun] = $this->find($scopeType, $scopeId, $run);
        $payload = $toolRun->result['data'] ?? $toolRun->result['findings'] ?? EmbeddedPageData::fromHtml($toolRun->result['html']) ?? [];

        return response()->streamDownload(static function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, self::fileName($scope, $toolRun, 'json'), ['Content-Type' => 'application/json']);
    }

    public function downloadBookingCsv(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        return $this->csv($scopeType, $scopeId, $run, 'booking_csv', 'booking');
    }

    public function downloadHistoryCsv(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        return $this->csv($scopeType, $scopeId, $run, 'history_csv', 'history');
    }

    public function downloadCsv(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        return $this->csv($scopeType, $scopeId, $run, 'csv', null);
    }

    /** The System day inputs CSV the run kept as a file (not in the result), 404 when there is none. */
    public function downloadSystemDayCsv(string $scopeType, string $scopeId, string $run): SymfonyResponse
    {
        [$scope, $toolRun] = $this->find($scopeType, $scopeId, $run);
        $stream = ToolRunFiles::readStream($toolRun, 'system_day_inputs.csv');
        if (! is_resource($stream)) {
            abort(404);
        }

        return response()->streamDownload(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, str_replace('.csv', '_system_day_inputs.csv', self::fileName($scope, $toolRun, 'csv')), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** One of the run's stored CSV texts (`$key` in the result), named like the other downloads plus `_$suffix`. */
    private function csv(string $scopeType, string $scopeId, string $run, string $key, ?string $suffix): SymfonyResponse
    {
        [$scope, $toolRun] = $this->find($scopeType, $scopeId, $run);
        $text = $toolRun->result[$key] ?? null;
        if (! is_string($text)) {
            abort(404);
        }

        $name = self::fileName($scope, $toolRun, 'csv');
        if ($suffix !== null) {
            $name = str_replace('.csv', "_{$suffix}.csv", $name);
        }

        return response()->streamDownload(static function () use ($text): void {
            echo $text;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The route parameters of a run's page and downloads: a dataset by id, or Unfiled as `unfiled/me`
     * (always the signed-in user's own, so the url names no id). Throws for an Environment scope: no
     * environment-scoped tool has its own run view.
     *
     * @return array{scopeType: string, scopeId: string, run: string}
     */
    public static function params(Model $scope, ToolRun $run): array
    {
        return match (true) {
            $scope instanceof User => ['scopeType' => 'unfiled', 'scopeId' => 'me', 'run' => (string) $run->getKey()],
            $scope instanceof Dataset => ['scopeType' => 'datasets', 'scopeId' => (string) $scope->getRouteKey(), 'run' => (string) $run->getKey()],
            default => throw new InvalidArgumentException('Only dataset and unfiled runs have a page here.'),
        };
    }

    /** @return array{0: Dataset|User, 1: ToolRun} the user's dataset (or the user for Unfiled) and a finished run on it that holds a page, else 404 */
    private function find(string $scopeType, string $scopeId, string $run): array
    {
        $scope = match ($scopeType) {
            'datasets' => Dataset::query()->ownedByCurrentUser()->findOrFail($scopeId),
            'unfiled' => $scopeId === 'me' ? auth()->user() : abort(404),
            default => abort(404),
        };
        $toolRun = ToolRun::query()
            ->where('scope_type', $scope->getMorphClass())
            ->where('scope_id', (string) $scope->getKey())
            ->findOrFail($run);

        if (blank($toolRun->result['html'] ?? null)) {
            abort(404);
        }

        return [$scope, $toolRun];
    }

    /**
     * <tool>_<account>_<dataset>_<week Monday or run date>[_activity<id> or _worktask<id>].<ext>, with `unfiled`
     * in place of <account>_<dataset> for an Unfiled run, cleaned to letters, digits, _ and -. The activity part
     * names what a trace run traced.
     */
    public static function fileName(Model $scope, ToolRun $run, string $extension): string
    {
        $clean = static fn (?string $part): string => preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $part);
        $date = $run->summary['week'] ?? $run->summary['first_date'] ?? $run->run_date?->toDateString() ?? 'undated';

        $activityId = $run->summary['activity_id'] ?? $run->inputs['options']['activity_id'] ?? null;
        $workTaskId = $run->summary['work_task_id'] ?? $run->inputs['options']['work_task_id'] ?? null;
        $subject = match (true) {
            is_scalar($activityId) && trim((string) $activityId) !== '' => '_activity'.$clean(trim((string) $activityId)),
            is_scalar($workTaskId) && trim((string) $workTaskId) !== '' => '_worktask'.$clean(trim((string) $workTaskId)),
            default => '',
        };
        $where = $scope instanceof Dataset ? $clean($scope->environment?->account_id).'_'.$clean($scope->name) : 'unfiled';

        return $run->tool->value.'_'.$where.'_'.$clean($date).$subject.'.'.$extension;
    }
}
