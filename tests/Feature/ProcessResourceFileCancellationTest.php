<?php

use App\Jobs\ProcessResourceFile;
use Illuminate\Support\Facades\Cache;

function makeProcessResourceFileJob(string $jobId): ProcessResourceFile
{
    return new ProcessResourceFile(
        jobId: $jobId,
        path: 'irrelevant.json',
        regionIds: [],
    );
}

afterEach(function () {
    Cache::forget('resource-job:cancellation-test:cancelled');
    Cache::forget('resource-job:cancellation-test:status');
});

it('is not cancelled by default', function () {
    $job = makeProcessResourceFileJob('cancellation-test');

    expect(callProtectedMethod($job, 'isCancelled'))->toBeFalse();
});

it('is cancelled once the cache flag is set', function () {
    $job = makeProcessResourceFileJob('cancellation-test');
    Cache::put('resource-job:cancellation-test:cancelled', true);

    expect(callProtectedMethod($job, 'isCancelled'))->toBeTrue();
});

it('aborts and marks the job cancelled when abortIfCancelled runs on a cancelled job', function () {
    $job = makeProcessResourceFileJob('cancellation-test');
    Cache::put('resource-job:cancellation-test:cancelled', true);

    $aborted = callProtectedMethod($job, 'abortIfCancelled');

    expect($aborted)->toBeTrue()
        ->and(Cache::get('resource-job:cancellation-test:status'))->toBe('cancelled');
});

it('does not abort when the job has not been cancelled', function () {
    $job = makeProcessResourceFileJob('cancellation-test');

    $aborted = callProtectedMethod($job, 'abortIfCancelled');

    expect($aborted)->toBeFalse()
        ->and(Cache::get('resource-job:cancellation-test:status'))->toBeNull();
});
