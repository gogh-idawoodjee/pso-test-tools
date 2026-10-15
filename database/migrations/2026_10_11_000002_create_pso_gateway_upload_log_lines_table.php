<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pso_gateway_upload_log_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('pso_gateway_upload_id')->constrained('pso_gateway_uploads')->cascadeOnDelete();
            $table->string('level', 16);
            $table->text('text');
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index(['pso_gateway_upload_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pso_gateway_upload_log_lines');
    }
};
