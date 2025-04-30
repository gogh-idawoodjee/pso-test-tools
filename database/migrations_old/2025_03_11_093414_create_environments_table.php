<?php

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
        Schema::create('environments', function (Blueprint $table) {
            // For both SQLite and PostgreSQL, use string() for UUID
            $table->string('id')->primary(); // Use string for UUID storage in SQLite
            $table->string('account_id');
            $table->string('base_url');
            $table->string('description')->nullable();
            $table->string('name');
            $table->longText('password');
            $table->string('username');
            $table->foreignIdFor(User::class)->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
