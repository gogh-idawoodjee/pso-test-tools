<?php

namespace App\Http\Controllers;

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\ToolRun;
use Illuminate\Http\Response;
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

    public function page(string $dataset, string $run): Response
    {
        [, $toolRun] = $this->find($dataset, $run);

        return response($toolRun->result['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => $toolRun->tool === ToolId::PlanTravels ? self::SANDBOX_WITH_APP_LINKS : self::SANDBOX,
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function downloadHtml(string $dataset, string $run): SymfonyResponse
    {
        [$datasetModel, $toolRun] = $this->find($dataset, $run);

        return response()->streamDownload(static function () use ($toolRun): void {
            echo $toolRun->result['html'];
        }, self::fileName($datasetModel, $toolRun, 'html'), ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** The run's data file: the viewer's gathered JSON (`data`) or the audit's findings (`findings`). */
    public function downloadJson(string $dataset, string $run): SymfonyResponse
    {
        [$datasetModel, $toolRun] = $this->find($dataset, $run);
        $payload = $toolRun->result['data'] ?? $toolRun->result['findings'] ?? [];

        return response()->streamDownload(static function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, self::fileName($datasetModel, $toolRun, 'json'), ['Content-Type' => 'application/json']);
    }

    public function downloadBookingCsv(string $dataset, string $run): SymfonyResponse
    {
        return $this->csv($dataset, $run, 'booking_csv', 'booking');
    }

    public function downloadHistoryCsv(string $dataset, string $run): SymfonyResponse
    {
        return $this->csv($dataset, $run, 'history_csv', 'history');
    }

    public function downloadCsv(string $dataset, string $run): SymfonyResponse
    {
        return $this->csv($dataset, $run, 'csv', null);
    }

    /** One of the run's stored CSV texts (`$key` in the result), named like the other downloads plus `_$suffix`. */
    private function csv(string $dataset, string $run, string $key, ?string $suffix): SymfonyResponse
    {
        [$datasetModel, $toolRun] = $this->find($dataset, $run);
        $text = $toolRun->result[$key] ?? null;
        if (! is_string($text)) {
            abort(404);
        }

        $name = self::fileName($datasetModel, $toolRun, 'csv');
        if ($suffix !== null) {
            $name = str_replace('.csv', "_{$suffix}.csv", $name);
        }

        return response()->streamDownload(static function () use ($text): void {
            echo $text;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: Dataset, 1: ToolRun} the user's dataset and a finished run on it that holds a page, else 404 */
    private function find(string $dataset, string $run): array
    {
        $datasetModel = Dataset::query()->ownedByCurrentUser()->findOrFail($dataset);
        $toolRun = ToolRun::query()
            ->where('scope_type', $datasetModel->getMorphClass())
            ->where('scope_id', $datasetModel->getKey())
            ->findOrFail($run);

        if (blank($toolRun->result['html'] ?? null)) {
            abort(404);
        }

        return [$datasetModel, $toolRun];
    }

    /**
     * <tool>_<account>_<dataset>_<week Monday or run date>[_activity<id> or _worktask<id>].<ext>, cleaned to
     * letters, digits, _ and -. The activity part names what a trace run traced.
     */
    public static function fileName(Dataset $dataset, ToolRun $run, string $extension): string
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

        return $run->tool->value.'_'.$clean($dataset->environment?->account_id).'_'.$clean($dataset->name).'_'.$clean($date).$subject.'.'.$extension;
    }
}
