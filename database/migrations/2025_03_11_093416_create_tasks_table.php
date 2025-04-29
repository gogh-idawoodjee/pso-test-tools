<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // Use a 36-character string for UUID
            $table->string('customer_id', 36);   // Customer ID as string
            $table->string('name');
            $table->string('type');
            $table->string('status');
            $table->integer('duration');
            $table->dateTime('appt_window_start');
            $table->dateTime('appt_window_finish');
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
