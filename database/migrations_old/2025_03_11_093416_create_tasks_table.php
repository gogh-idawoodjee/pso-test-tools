<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // Use a 36-character string for UUID
            $table->string('name');
            $table->string('type');
            $table->string('status');
            $table->integer('duration');
            $table->dateTime('appt_window_start');
            $table->dateTime('appt_window_finish');
            $table->timestamps();

            $table->foreignIdFor(Customer::class);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
