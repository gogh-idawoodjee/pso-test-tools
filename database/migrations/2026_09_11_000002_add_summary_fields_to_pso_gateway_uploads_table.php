<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pso_gateway_uploads', function (Blueprint $table) {
            $table->string('dataset_id')->nullable()->after('original_filename');
            $table->timestamp('input_reference_datetime')->nullable()->after('dataset_id');
            $table->unsignedInteger('activity_count')->nullable()->after('input_reference_datetime');
            $table->unsignedInteger('resource_count')->nullable()->after('activity_count');
        });
    }

    public function down(): void
    {
        Schema::table('pso_gateway_uploads', function (Blueprint $table) {
            $table->dropColumn(['dataset_id', 'input_reference_datetime', 'activity_count', 'resource_count']);
        });
    }
};
