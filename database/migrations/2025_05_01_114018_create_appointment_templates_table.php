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
            // UUID primary key
            $table->string('id')->primary();

            // Foreign key to users.id (integer)
            $table->foreignIdFor(User::class)
                ->constrained()
                ->cascadeOnDelete();

            // Name column
            $table->string('name');
            $table->timestamps();
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
