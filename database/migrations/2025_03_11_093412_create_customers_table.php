<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->string('address');
            $table->string('city');
            $table->string('country');
            $table->uuid('id');
            $table->decimal('lat')->nullable();
            $table->decimal('long')->nullable();
            $table->string('name');
            $table->string('postcode');
            $table->foreignUuid('region_id');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
