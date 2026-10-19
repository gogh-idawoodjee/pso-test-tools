<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('regions')->nullable();
            $table->json('skills')->nullable();
            $table->string('appointment_request_id')->nullable();
            $table->string('booking_sla_type_id')->nullable();
            $table->dateTime('booked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['regions', 'skills', 'appointment_request_id', 'booking_sla_type_id', 'booked_at']);
        });
    }
};
