<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Jobs\LogTokenUsageJob;

class LogTokenUsage
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token) {
            LogTokenUsageJob::dispatch([
                'token_id' => $token->id,
                'user_id' => $user->id,
                'route' => $request->path(),
                'method' => $request->method(),
                'ip_address' => $request->ip(),
            ]);
        }

        return $response;
    }
}
