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
