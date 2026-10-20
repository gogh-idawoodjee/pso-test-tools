<?php

use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\AppointmentBooking;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

it('links back to the task from the booking page header', function () {
    $user = User::factory()->create();
    $environment = Environment::factory()->for($user)->create();
    $dataset = Dataset::factory()->for($environment)->create(['settings' => ['time_zone' => 'America/Toronto']]);
    $task = Task::factory()->for(Customer::factory()->for($dataset)->create())->create();
    $this->actingAs($user);

    Livewire::test(AppointmentBooking::class, ['record' => $task->getKey()])
        ->assertSee('Back to task')
        ->assertSee(TaskResource::getUrl('edit', ['record' => $task]));
});

it('shows each task header label once', function () {
    $user = User::factory()->create();
    $dataset = Dataset::factory()->for(Environment::factory()->for($user))->create();
    $task = Task::factory()->for(Customer::factory()->for($dataset)->create())->create();
    $this->actingAs($user);

    $html = Livewire::test(AppointmentBooking::class, ['record' => $task->getKey()])->html();

    foreach (['Duration', 'Base value', 'Status'] as $label) {
        expect(preg_match_all('/>\s*'.preg_quote($label, '/').'\s*</', $html))->toBe(1, $label.' should appear once');
    }
});
