<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_files', function (Blueprint $table) {
            // A name the user gives the file, shown in place of the filename in lists and dropdowns.
            $table->string('name', 60)->nullable()->after('original_filename');
        });
    }

    public function down(): void
    {
        Schema::table('export_files', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
