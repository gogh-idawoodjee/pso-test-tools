<?php

use App\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        Schema::create('datasets', function (Blueprint $table) {
            // Use string() for UUID compatibility across SQLite and PostgreSQL
            $table->string('id')->primary(); // Use string for UUID storage in SQLite
            $table->string('name');
            $table->string('rota');
            $table->foreignIdFor(Environment::class); // Use string for foreign key UUID
            $table->timestamps();

            // Foreign key constraint for environment_id, use 'string' for SQLite compatibility
//            $table->foreign('environment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};
