<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_runs', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('scope');
            $table->string('tool', 32);
            $table->date('run_date')->nullable();
            $table->json('inputs');
            $table->foreignIdFor(User::class, 'started_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16);
            $table->longText('result')->nullable();   // encrypted JSON, so longText not json
            $table->json('warnings')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
    
            $table->index(['scope_type', 'scope_id', 'tool', 'run_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_runs');
    }
};
