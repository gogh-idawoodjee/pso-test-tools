<?php

namespace App\Http\Controllers;

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\ToolRun;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The load audit's rendered page and downloads. The page carries text from customer files and
 * builds itself with JavaScript, so it is served under a CSP sandbox and never with the app's origin.
 */
class LoadAuditRunController extends Controller
{
    public const SANDBOX = 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation';

    public function dashboard(string $dataset, string $run): Response
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
        }, $this->fileName($datasetModel, $toolRun, 'html'), ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function downloadFindings(string $dataset, string $run): SymfonyResponse
    {
        [$datasetModel, $toolRun] = $this->find($dataset, $run);

        return response()->streamDownload(static function () use ($toolRun): void {
            echo json_encode($toolRun->result['findings'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $this->fileName($datasetModel, $toolRun, 'json'), ['Content-Type' => 'application/json']);
    }

    /** @return array{0: Dataset, 1: ToolRun} the user's dataset and a finished load audit run on it, else 404 */
    private function find(string $dataset, string $run): array
    {
        $datasetModel = Dataset::query()->ownedByCurrentUser()->findOrFail($dataset);
        $toolRun = ToolRun::query()
            ->where('scope_type', $datasetModel->getMorphClass())
            ->where('scope_id', $datasetModel->getKey())
            ->where('tool', ToolId::LoadAudit)
            ->findOrFail($run);

        if (blank($toolRun->result['html'] ?? null)) {
            abort(404);
        }

        return [$datasetModel, $toolRun];
    }

    public static function fileName(Dataset $dataset, ToolRun $run, string $extension): string
    {
        $clean = static fn (?string $part): string => preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $part);

        return 'load_audit_'.$clean($dataset->environment?->account_id).'_'.$clean($dataset->name).'_'.($run->run_date?->toDateString() ?? 'undated').'.'.$extension;
    }
}
