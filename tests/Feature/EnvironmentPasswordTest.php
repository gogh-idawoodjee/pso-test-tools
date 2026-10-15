<?php

use App\Filament\Resources\EnvironmentResource\Pages\EditEnvironment;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;

it('fills the edit form with the decrypted password and keeps it single-encrypted on save', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $environment = Environment::factory()->create([
        'user_id' => $user->id,
        'base_url' => 'https://pso.example.test',
        'password' => Crypt::encryptString('SecretValue'),
    ]);

    Livewire::test(EditEnvironment::class, ['record' => $environment->getRouteKey()])
        ->assertSchemaStateSet(['password' => 'SecretValue'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Crypt::decryptString($environment->refresh()->password))->toBe('SecretValue');
});
