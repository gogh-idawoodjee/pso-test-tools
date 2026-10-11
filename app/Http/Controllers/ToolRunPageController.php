<?php

namespace App\Http\Controllers;

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

    public function page(string $dataset, string $run): Response
    {
        [, $toolRun] = $this->find($dataset, $run);

        return response($toolRun->result['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::SANDBOX,
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

    /** <tool>_<account>_<dataset>_<week Monday or run date>.<ext>, cleaned to letters, digits, _ and -. */
    public static function fileName(Dataset $dataset, ToolRun $run, string $extension): string
    {
        $clean = static fn (?string $part): string => preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $part);
        $date = $run->summary['week'] ?? $run->run_date?->toDateString() ?? 'undated';

        return $run->tool->value.'_'.$clean($dataset->environment?->account_id).'_'.$clean($dataset->name).'_'.$clean($date).'.'.$extension;
    }
}
