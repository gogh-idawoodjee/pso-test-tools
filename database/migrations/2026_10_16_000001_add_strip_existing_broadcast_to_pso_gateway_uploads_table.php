<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pso_gateway_uploads', function (Blueprint $table): void {
            $table->boolean('strip_existing_broadcast')->default(true)->after('skip_webhook');
        });
    }

    public function down(): void
    {
        Schema::table('pso_gateway_uploads', function (Blueprint $table): void {
            $table->dropColumn('strip_existing_broadcast');
        });
    }
};
