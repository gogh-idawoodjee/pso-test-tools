<?php

use App\Enums\Tool as ToolId;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ToolRunsRelationManager as DatasetRuns;
use App\Filament\Widgets\RecentRuns;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => ['html' => '<p>x</p>']]);
});

/** SQL of every row-fetching tool_runs select run by the callback (not exists or count probes). */
function toolRunSelects(Closure $callback): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_starts_with($query->sql, 'select') && ! str_starts_with($query->sql, 'select exists') && ! str_starts_with($query->sql, 'select count') && str_contains($query->sql, 'from "tool_runs"')) {
            $queries[] = $query->sql;
        }
    });
    $callback();

    return $queries;
}

/** The SQL before its WHERE clause, where the select list lives. */
function selectList(string $sql): string
{
    return explode(' where ', $sql)[0];
}

it('does not select the result column on the Runs tab', function () {
    $queries = toolRunSelects(fn () => Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertCanSeeTableRecords([$this->run]));

    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect(selectList($sql))->not->toMatch('/"result"|"tool_runs"\.\*|select \*/');
    }
});

it('does not select the result column in Recent runs', function () {
    $queries = toolRunSelects(fn () => Livewire::test(RecentRuns::class)->assertCanSeeTableRecords([$this->run]));

    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect(selectList($sql))->not->toMatch('/"result"|"tool_runs"\.\*|select \*/');
    }
});

it('loads the run once per render of the run page', function () {
    $test = null;
    $mount = toolRunSelects(function () use (&$test) {
        $test = Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id]);
    });
    expect($mount)->toHaveCount(1);

    $refresh = toolRunSelects(fn () => $test->call('$refresh'));
    expect($refresh)->toHaveCount(1);
});
