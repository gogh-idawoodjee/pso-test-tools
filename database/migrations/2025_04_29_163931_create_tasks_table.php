<?php

use App\Enums\TaskStatus;
use App\Models\TaskType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Customer;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->dateTime('appt_window_finish')->nullable();
            $table->dateTime('appt_window_start')->nullable();
            $table->integer('duration');
            $table->string('id');
            $table->string('status')->default(TaskStatus::IGNORE->value);
            $table->foreignIdFor(Customer::class);
            $table->foreignIdFor(TaskType::class);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
