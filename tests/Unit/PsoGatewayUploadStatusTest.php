<?php

use App\Enums\PsoGatewayUploadStatus;

it('reports succeeded and failed as terminal states', function () {
    expect(PsoGatewayUploadStatus::SUCCEEDED->isTerminal())->toBeTrue();
    expect(PsoGatewayUploadStatus::FAILED->isTerminal())->toBeTrue();
});

it('reports queued, compressing, and uploading as non-terminal states', function (PsoGatewayUploadStatus $status) {
    expect($status->isTerminal())->toBeFalse();
})->with([PsoGatewayUploadStatus::QUEUED, PsoGatewayUploadStatus::COMPRESSING, PsoGatewayUploadStatus::UPLOADING]);

it('reports an increasing progress percentage through each stage, ending at 100', function () {
    expect(PsoGatewayUploadStatus::QUEUED->progressPercent())->toBeLessThan(PsoGatewayUploadStatus::COMPRESSING->progressPercent());
    expect(PsoGatewayUploadStatus::COMPRESSING->progressPercent())->toBeLessThan(PsoGatewayUploadStatus::UPLOADING->progressPercent());
    expect(PsoGatewayUploadStatus::UPLOADING->progressPercent())->toBeLessThan(PsoGatewayUploadStatus::SUCCEEDED->progressPercent());
    expect(PsoGatewayUploadStatus::SUCCEEDED->progressPercent())->toBe(100);
    expect(PsoGatewayUploadStatus::FAILED->progressPercent())->toBe(100);
});
