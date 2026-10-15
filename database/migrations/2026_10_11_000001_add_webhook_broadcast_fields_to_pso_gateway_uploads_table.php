<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pso_gateway_uploads', function (Blueprint $table) {
            $table->string('dataset_id_override')->nullable()->after('original_filename');
            $table->boolean('skip_webhook')->default(false)->after('dataset_id_override');
            $table->json('broadcast_settings')->nullable()->after('skip_webhook');
            $table->unsignedSmallInteger('response_status')->nullable()->after('internal_id');
            $table->text('response_body')->nullable()->after('response_status');
            $table->string('webhook_url')->nullable()->after('response_body');
            $table->string('webhook_view_url')->nullable()->after('webhook_url');
            $table->unsignedBigInteger('wire_bytes')->nullable()->after('compressed_size_bytes');
            $table->unsignedInteger('post_duration_ms')->nullable()->after('wire_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('pso_gateway_uploads', function (Blueprint $table) {
            $table->dropColumn([
                'dataset_id_override',
                'skip_webhook',
                'broadcast_settings',
                'response_status',
                'response_body',
                'webhook_url',
                'webhook_view_url',
                'wire_bytes',
                'post_duration_ms',
            ]);
        });
    }
};
