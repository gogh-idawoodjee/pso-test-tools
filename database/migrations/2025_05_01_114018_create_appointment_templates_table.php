<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppointmentTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointment_templates', static function (Blueprint $table) {
            $table->uuid('id')->primary(); // Clean UUID primary key
            $table->string('template_id'); // Logical ID like 'INSTALL', 'REPAIR'

            $table->foreignIdFor(User::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->timestamps();

// Prevent duplicate template IDs per user
            $table->unique(['template_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_templates');
    }
}
