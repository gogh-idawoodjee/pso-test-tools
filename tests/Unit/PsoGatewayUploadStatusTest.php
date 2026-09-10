<?php

use App\Enums\PsoGatewayUploadStatus;

it('reports succeeded and failed as terminal states', function () {
    expect(PsoGatewayUploadStatus::SUCCEEDED->isTerminal())->toBeTrue();
    expect(PsoGatewayUploadStatus::FAILED->isTerminal())->toBeTrue();
});

it('reports queued, compressing, and uploading as non-terminal states', function (PsoGatewayUploadStatus $status) {
    expect($status->isTerminal())->toBeFalse();
})->with([PsoGatewayUploadStatus::QUEUED, PsoGatewayUploadStatus::COMPRESSING, PsoGatewayUploadStatus::UPLOADING]);
