<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pso_gateway_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('pso_environment_id')
                ->constrained('environments')
                ->cascadeOnDelete();

            $table->foreignIdFor(User::class, 'initiated_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('stored_path');
            $table->string('original_filename');
            $table->unsignedBigInteger('file_size_bytes');
            $table->unsignedBigInteger('compressed_size_bytes')->nullable();

            $table->string('status')->default('queued');
            $table->string('internal_id')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pso_gateway_uploads');
    }
};
