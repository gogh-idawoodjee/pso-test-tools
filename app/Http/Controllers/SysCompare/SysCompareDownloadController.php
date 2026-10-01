<?php

namespace App\Http\Controllers\SysCompare;

use App\Http\Controllers\Controller;
use App\Support\SysCompare\Storage\SysCompareStorage;
use App\Support\SysCompare\SysCompareArtifact;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the generated outputs of a run to the user who created it, and nobody else.
 */
class SysCompareDownloadController extends Controller
{
    public function __invoke(Request $request, SysCompareStorage $storage, string $run, string $artifact): BinaryFileResponse
    {
        $case = SysCompareArtifact::tryFrom($artifact) ?? abort(404);
        $path = $storage->runFile((int) $request->user()->getAuthIdentifier(), $run, $case) ?? abort(404);

        $headers = [
            'Content-Type' => $case->mimeType(),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($case === SysCompareArtifact::Report && $request->boolean('inline')) {
            // Shown in a sandboxed iframe: scripts may run (click-to-sort) but the page
            // has no access to the app, and cannot make any request of its own.
            $headers['Content-Security-Policy'] = "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'";

            return response()->file($path, $headers + ['Content-Disposition' => 'inline; filename="'.$case->fileName().'"']);
        }

        return response()->download($path, $case->fileName(), $headers);
    }
}
