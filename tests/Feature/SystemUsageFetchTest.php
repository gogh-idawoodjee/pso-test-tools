<?php

use App\Enums\HttpMethod;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;
use Illuminate\Support\Facades\Http;

it('sends minDate and maxDate as query params on a GET request', function () {
    Http::fake([
        '*' => Http::response(['data' => ['ScheduleDataUsages' => []]], 200),
    ]);

    $page = new EnvironmentTools;

    $page->sendToPSONew(
        'usage',
        null,
        ['environment' => ['baseUrl' => 'https://example.test', 'accountId' => 'acc-1', 'datasetId' => 'ds-1', 'token' => 'tok-123']],
        HttpMethod::GET,
        true,
        ['minDate' => '2026-01-01T00:00:00', 'maxDate' => '2026-01-02T00:00:00'],
    );

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && str_contains((string) $request->url(), 'minDate=2026-01-01T00%3A00%3A00')
            && str_contains((string) $request->url(), 'maxDate=2026-01-02T00%3A00%3A00')
            && $request->hasHeader('baseUrl', 'https://example.test')
            && $request->hasHeader('accountId', 'acc-1')
            && $request->hasHeader('datasetId', 'ds-1')
            && $request->hasHeader('token', 'tok-123');
    });
});

it('sends no query string when no query params are given', function () {
    Http::fake([
        '*' => Http::response(['data' => ['ScheduleDataUsages' => []]], 200),
    ]);

    $page = new EnvironmentTools;

    $page->sendToPSONew(
        'usage',
        null,
        ['environment' => ['baseUrl' => 'https://example.test', 'accountId' => 'acc-1', 'datasetId' => 'ds-1', 'token' => 'tok-123']],
        HttpMethod::GET,
        true,
    );

    Http::assertSent(fn ($request) => ! str_contains((string) $request->url(), '?'));
});
