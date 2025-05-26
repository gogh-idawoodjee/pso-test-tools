<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSlotUsageRulesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('slot_usage_rules', static function (Blueprint $table) {
            // UUID primary key
            $table->string('id')->primary();

            // Foreign key to users.id (integer)
            $table->foreignIdFor(User::class)
                ->constrained()
                ->cascadeOnDelete();

            // Name of the rule
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slot_usage_rules');
    }
}
