<?php

use App\Filament\Resources\EnvironmentResource\Pages\ListEnvironments;
use App\Models\Environment;
use App\Models\User;
use App\Support\PsoEnvironmentsExporter;
use Livewire\Livewire;

it('builds psd1 entries keyed by camelCased environment name with the gateway path appended', function () {
    $environments = collect([
        Environment::factory()->make(['name' => 'the Drome', 'password' => 'PasswordValue', 'base_url' => 'https://pso.thetechnodro.me/', 'account_id' => 'Default', 'username' => 'INT_USER']),
        Environment::factory()->make(['name' => 'sask TST', 'base_url' => 'https://sast-pso-tst.ifs.cloud', 'account_id' => 'sate', 'username' => 'GOGH_INT']),
    ]);

    $psd1 = app(PsoEnvironmentsExporter::class)->toPsd1($environments);

    expect($psd1)
        ->toContain("    theDrome = @{\n        GatewayBaseUrl = \"https://pso.thetechnodro.me/IFSSchedulingRESTfulGateway/api/v1\"\n        AccountId      = \"Default\"\n        UserId         = \"INT_USER\"\n    }")
        ->toContain('    saskTST = @{')
        ->toStartWith('@{')
        ->toEndWith("}\n")
        ->not->toContain('PasswordValue');
});

it('dedupes keys case-insensitively, prefixes numeric names and escapes powershell specials', function () {
    $environments = collect([
        Environment::factory()->make(['name' => 'Dev', 'username' => 'a$b"c']),
        Environment::factory()->make(['name' => 'dev']),
        Environment::factory()->make(['name' => '2024 UAT']),
    ]);

    $exporter = app(PsoEnvironmentsExporter::class);

    expect(array_keys($exporter->entries($environments)))->toBe(['dev', 'dev2', 'env2024UAT'])
        ->and($exporter->toPsd1($environments))->toContain('UserId         = "a`$b`"c"');
});

it('downloads only the current user\'s environments as pso-environments.psd1', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Environment::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);
    Environment::factory()->create(['name' => 'Someone Else']);

    Livewire::test(ListEnvironments::class)
        ->callAction('export_psd1')
        ->assertFileDownloaded('pso-environments.psd1');

    $content = app(PsoEnvironmentsExporter::class)->toPsd1(Environment::query()->get());

    expect($content)->toContain('mine = @{')->not->toContain('someoneElse');
});

it('opens the JSON slide-over', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Environment::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);

    Livewire::test(ListEnvironments::class)
        ->mountAction('show_json')
        ->assertActionMounted('show_json')
        ->assertMountedActionModalSee('"mine": {')
        ->assertMountedActionModalSee('/IFSSchedulingRESTfulGateway/api/v1');
});
