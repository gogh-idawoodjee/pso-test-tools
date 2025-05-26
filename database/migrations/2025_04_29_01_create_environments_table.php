<?php

use App\Models\User;
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
        Schema::create('environments', function (Blueprint $table) {
            // Use UUID as primary key
            $table->uuid('id')->primary();
            $table->string('account_id');
            $table->string('base_url');
            $table->string('description')->nullable();
            $table->string('name');
            $table->string('slug');

            $table->longText('password');
            $table->string('username');

            // Foreign key to users.id (integer)
            $table->foreignIdFor(User::class)
                ->constrained()
                ->cascadeOnDelete();

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
