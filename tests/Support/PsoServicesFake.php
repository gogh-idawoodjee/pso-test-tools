<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the pso-services session endpoints for Http::fake(). POST is the
 * logon, DELETE is the logoff, both on the same URL.
 */
class PsoServicesFake
{
    public static function sessionUrl(): string
    {
        $version = config('psott.pso-services-api-version');

        return 'https://'.config('psott.pso-services-api').'/api/'.($version ? "{$version}/" : '').'session';
    }

    /**
     * @return array<string, callable>
     */
    public static function session(string $token = 'tok-abc', int $logonStatus = 200, int $logoffStatus = 200): array
    {
        return [
            self::sessionUrl() => static function (Request $request) use ($token, $logonStatus, $logoffStatus) {
                if ($request->method() === 'DELETE') {
                    return $logoffStatus === 200
                        ? Http::response(['data' => 'Logged off.', 'message' => 'Logged off.', 'status' => 200], 200)
                        : Http::response(['error' => 'Internal server error. Try again later.', 'details' => null], $logoffStatus);
                }

                return $logonStatus === 200
                    ? Http::response(['data' => 'Auth was successful.', 'message' => ['SessionToken' => $token], 'status' => 200], 200)
                    : Http::response(['error' => 'Unauthorized. Please check your session or login credentials.', 'details' => null], $logonStatus);
            },
        ];
    }
}
