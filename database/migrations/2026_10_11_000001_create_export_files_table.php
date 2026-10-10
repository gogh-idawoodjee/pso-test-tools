<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_files', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('scope');
            $table->string('kind', 16);
            $table->foreignIdFor(User::class, 'uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('content_type', 8);
            $table->json('summary')->nullable();
            $table->string('status', 16);
            $table->text('error_message')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_files');
    }
};
