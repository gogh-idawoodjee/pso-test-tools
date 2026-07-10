<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TravelCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Log::debug('Travel callback payload received', ['payload' => $request->all()]);

        $validated = $request->validate([
            'travelLogId' => 'required|string',
        ]);

        $cacheKey = 'travel-analysis:'.$validated['travelLogId'];

        if (! Cache::has($cacheKey)) {
            Log::warning('Travel callback received for unknown travelLogId', [
                'travelLogId' => $validated['travelLogId'],
            ]);

            return response()->json(['error' => 'Unknown travelLogId'], 404);
        }

        $results = $request->except('travelLogId');

        Cache::put($cacheKey, [
            'status' => 'complete',
            'results' => $results,
            'completed_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));

        Log::info('Travel callback received', [
            'travelLogId' => $validated['travelLogId'],
            'results' => $results,
        ]);

        return response()->json(['message' => 'Results received']);
    }
}
