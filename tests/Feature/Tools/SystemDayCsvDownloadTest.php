<?php

use App\Enums\Tool as ToolId;
use App\Http\Controllers\ToolRunPageController;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Tools\ToolRunFiles;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->run = ToolRun::factory()->for($this->user, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'run_date' => '2026-10-04',
        'result' => ['html' => '<p>x</p>', 'findings' => []],
        'summary' => ['system_day' => true],
    ]);
    $this->actingAs($this->user);
});

it('downloads the stored System day inputs CSV', function () {
    $path = tempnam(sys_get_temp_dir(), 'sd');
    file_put_contents($path, "a,b\n1,2\n");
    ToolRunFiles::put($this->run, 'system_day_inputs.csv', $path);
    unlink($path);

    $response = $this->get(route('tool-runs.download-system-day-csv', ToolRunPageController::params($this->user, $this->run)));

    $response->assertOk()->assertDownload('load_audit_unfiled_2026-10-04_system_day_inputs.csv');
    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($response->streamedContent())->toBe("a,b\n1,2\n");
});

it('gives 404 for another user run', function () {
    $theirs = ToolRun::factory()->for(User::factory()->create(), 'scope')->create(['result' => ['html' => '<p>x</p>']]);

    $this->get(route('tool-runs.download-system-day-csv', ['scopeType' => 'unfiled', 'scopeId' => 'me', 'run' => $theirs->id]))->assertNotFound();
});

it('gives 404 when no inputs CSV is stored', function () {
    $this->get(route('tool-runs.download-system-day-csv', ToolRunPageController::params($this->user, $this->run)))->assertNotFound();
});
