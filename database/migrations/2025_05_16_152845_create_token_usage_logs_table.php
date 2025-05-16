<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('token_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('token_id'); // No foreign key!
            $table->string('ip_address')->nullable();
            $table->string('method');
            $table->string('route');
            $table->json('metadata')->nullable(); // optional extras: user agent, etc
            $table->timestamps();

            // Indexes for filtering
            $table->index('token_id');
            $table->index('route');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('token_usage_logs');
    }

};
