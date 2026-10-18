<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_runs', static function (Blueprint $table) {
            // Encrypted JSON: free text the user typed for a run (labels, title, notes), kept out of the plain inputs column.
            // mediumText: 20,000 characters of notes, encrypted and base64 encoded, can pass the 64 KB of a MySQL text column.
            $table->mediumText('private_inputs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tool_runs', static function (Blueprint $table) {
            $table->dropColumn('private_inputs');
        });
    }
};
