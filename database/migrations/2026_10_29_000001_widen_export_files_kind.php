<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 16 characters was too short for appointment_history (19), so its uploads failed on MySQL.
 * SQLite ignores the length, which is why the tests never caught it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_files', function (Blueprint $table): void {
            $table->string('kind', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('export_files', function (Blueprint $table): void {
            $table->string('kind', 16)->change();
        });
    }
};
