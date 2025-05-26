<?php

use App\Enums\TaskStatus;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            // UUID primary key
            $table->uuid('id')->primary();
            $table->string('friendly_id')->unique();
            $table->string('base_value');
            // Appointment windows
            $table->dateTime('appt_window_start')->nullable();
            $table->dateTime('appt_window_finish')->nullable();

            // Duration and status
            $table->integer('duration');
            $table->string('status')->default(TaskStatus::IGNORE->value);

            // Foreign keys (UUIDs)
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            // Foreign key to users.id (integer)
            $table->foreignIdFor(User::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignUuid('task_type_id')
                ->constrained('task_types')
                ->cascadeOnDelete();

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
