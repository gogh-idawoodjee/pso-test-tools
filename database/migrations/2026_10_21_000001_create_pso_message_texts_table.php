<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pso_message_texts', function (Blueprint $table) {
            $table->id();
            $table->string('language_id', 16);
            $table->unsignedInteger('message_id');
            $table->text('text');
            $table->timestamps();

            $table->unique(['language_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pso_message_texts');
    }
};
