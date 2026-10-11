<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_runs', static function (Blueprint $table) {
            $table->json('progress')->nullable()->after('status');   // {label, percent, seconds_left} while running
            $table->json('summary')->nullable()->after('result');    // small plain facts for lists (e.g. counts), never customer text
        });
    }

    public function down(): void
    {
        Schema::table('tool_runs', static function (Blueprint $table) {
            $table->dropColumn(['progress', 'summary']);
        });
    }
};
