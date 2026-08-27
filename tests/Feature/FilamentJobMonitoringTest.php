<?php

use App\Filament\Pages\FilterLoadFile;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Cache::forget('resource-job:job-monitoring-test:status');
    Cache::forget('resource-job:job-monitoring-test:created_at');
});

it('records a created_at cache timestamp when a job starts', function () {
    $page = new FilterLoadFile;
    callProtectedMethod($page, 'startJob', ['resource-job']);

    $key = callProtectedMethod($page, 'getJobCacheKey', ['created_at']);

    try {
        expect(Cache::get($key))->toBeGreaterThan(0);
    } finally {
        Cache::forget($key);
        Cache::forget(callProtectedMethod($page, 'getJobCacheKey', ['status']));
    }
});

it('is not timed out immediately after starting', function () {
    $page = new FilterLoadFile;
    setProtectedProperty($page, 'jobKey', 'resource-job');
    setProtectedProperty($page, 'jobId', 'job-monitoring-test');
    setProtectedProperty($page, 'status', 'processing');
    Cache::put(callProtectedMethod($page, 'getJobCacheKey', ['created_at']), time());

    expect(callProtectedMethod($page, 'isJobTimedOut'))->toBeFalse();
});

it('is timed out once past the configured threshold', function () {
    $page = new FilterLoadFile;
    setProtectedProperty($page, 'jobKey', 'resource-job');
    setProtectedProperty($page, 'jobId', 'job-monitoring-test');
    setProtectedProperty($page, 'status', 'processing');
    setProtectedProperty($page, 'jobTimeoutSeconds', 5);
    Cache::put(callProtectedMethod($page, 'getJobCacheKey', ['created_at']), time() - 10);

    expect(callProtectedMethod($page, 'isJobTimedOut'))->toBeTrue();
});

it('never times out once the job has reached a terminal status', function (string $terminalStatus) {
    $page = new FilterLoadFile;
    setProtectedProperty($page, 'jobKey', 'resource-job');
    setProtectedProperty($page, 'jobId', 'job-monitoring-test');
    setProtectedProperty($page, 'jobTimeoutSeconds', 5);
    setProtectedProperty($page, 'status', $terminalStatus);
    Cache::put(callProtectedMethod($page, 'getJobCacheKey', ['created_at']), time() - 10);

    expect(callProtectedMethod($page, 'isJobTimedOut'))->toBeFalse();
})->with(['complete', 'failed', 'cancelled']);

it('is never timed out if created_at was never recorded', function () {
    $page = new FilterLoadFile;
    setProtectedProperty($page, 'jobKey', 'resource-job');
    setProtectedProperty($page, 'jobId', 'job-monitoring-test');
    setProtectedProperty($page, 'status', 'processing');

    expect(callProtectedMethod($page, 'isJobTimedOut'))->toBeFalse();
});

it('formats elapsed time as minutes:seconds', function () {
    $page = new FilterLoadFile;
    $page->jobCreatedAt = now()->subSeconds(125);

    expect($page->getElapsedTime())->toBe('2:05');
});

it('returns 0:00 when no job has been created', function () {
    $page = new FilterLoadFile;
    $page->jobCreatedAt = null;

    expect($page->getElapsedTime())->toBe('0:00');
});
