<?php

use App\Enums\ExportKind;
use App\Enums\Tool;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the tables and the settings column', function () {
    expect(Schema::hasTable('export_files'))->toBeTrue()
        ->and(Schema::hasTable('tool_runs'))->toBeTrue()
        ->and(Schema::hasColumn('datasets', 'settings'))->toBeTrue();
});

it('maps kinds to the scope they are allowed on', function () {
    expect(ExportKind::Sys->allows(Environment::class))->toBeTrue()
        ->and(ExportKind::Load->allows(Dataset::class))->toBeTrue()
        ->and(ExportKind::Audits->extensions())->toBe(['zip'])
        ->and(ExportKind::Load->extensions())->toBe(['xml', 'json', 'zip'])
        ->and(Tool::LoadAudit->scope())->toBe(Dataset::class)
        ->and(Tool::SysCompare->scope())->toBe(Environment::class);
});

it('refuses a sys file on a dataset and a load file on an environment', function () {
    $environment = Environment::factory()->create();
    $dataset = Dataset::factory()->for($environment)->create();

    expect(fn () => ExportFile::factory()->for($dataset, 'scope')->create(['kind' => ExportKind::Sys]))
        ->toThrow(InvalidArgumentException::class, 'SYS');
    expect(fn () => ExportFile::factory()->for($environment, 'scope')->create(['kind' => ExportKind::Load]))
        ->toThrow(InvalidArgumentException::class);
});

it('scopes export files and runs to the logged-in user through the dataset or environment', function () {
    $me = User::factory()->create();
    $myEnvironment = Environment::factory()->for($me)->create();
    $theirEnvironment = Environment::factory()->create();
    $mine = Dataset::factory()->for($myEnvironment)->create();
    $theirs = Dataset::factory()->for($theirEnvironment)->create();
    ExportFile::factory()->for($mine, 'scope')->create(['original_filename' => 'mine.xml']);
    ExportFile::factory()->for($theirs, 'scope')->create(['original_filename' => 'theirs.xml']);
    ExportFile::factory()->for($myEnvironment, 'scope')->sys()->create(['original_filename' => 'mine-sys.xml']);
    ExportFile::factory()->for($theirEnvironment, 'scope')->sys()->create(['original_filename' => 'theirs-sys.xml']);
    ToolRun::factory()->for($mine, 'scope')->create();
    ToolRun::factory()->for($theirs, 'scope')->create();

    $this->actingAs($me);

    expect(ExportFile::pluck('original_filename')->sort()->values()->all())->toBe(['mine-sys.xml', 'mine.xml'])
        ->and(ToolRun::count())->toBe(1)
        ->and($mine->exportFiles()->count())->toBe(1)
        ->and($myEnvironment->exportFiles()->count())->toBe(1);
});

it('stores any kind unfiled on a user and shows it only to that user', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();
    $mySys = ExportFile::factory()->for($me, 'scope')->sys()->create(['original_filename' => 'my-sys.xml']);
    ExportFile::factory()->for($me, 'scope')->create(['kind' => ExportKind::Audits, 'original_filename' => 'my-audits.zip', 'content_type' => 'zip']);
    ExportFile::factory()->for($them, 'scope')->create(['original_filename' => 'their-load.xml']);
    ToolRun::factory()->for($me, 'scope')->create();
    ToolRun::factory()->for($them, 'scope')->create();

    $this->actingAs($me);

    expect(ExportFile::pluck('original_filename')->sort()->values()->all())->toBe(['my-audits.zip', 'my-sys.xml'])
        ->and(ToolRun::count())->toBe(1)
        ->and($me->exportFiles()->count())->toBe(2)
        ->and($me->toolRuns()->count())->toBe(1)
        ->and($mySys->fresh()->scope)->toBeInstanceOf(User::class)
        ->and((string) $mySys->fresh()->scope_id)->toBe((string) $me->id);
});

it('matches the unfiled owner on the user id as a string', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    expect(ExportFile::query()->getBindings())->toContain((string) $me->id)
        ->and(ToolRun::query()->getBindings())->toContain((string) $me->id);
});

it('sets expires_at twelve hours after creation and knows when it is expired', function () {
    config(['exports.ttl_hours' => 12]);
    $this->travelTo(now()->startOfMinute());
    $file = ExportFile::factory()->create();

    expect($file->expires_at->eq(now()->addHours(12)))->toBeTrue()
        ->and($file->isExpired())->toBeFalse();

    $this->travel(13)->hours();
    expect($file->fresh()->isExpired())->toBeTrue();
});

it('stores the result encrypted', function () {
    $run = ToolRun::factory()->create(['result' => ['findings' => ['one']]]);

    $raw = DB::table('tool_runs')->where('id', $run->id)->value('result');

    expect($raw)->not->toContain('findings')
        ->and($run->fresh()->result)->toBe(['findings' => ['one']]);
});
