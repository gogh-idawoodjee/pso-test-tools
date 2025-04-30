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
            // Use UUID as primary key
            $table->uuid('id')->primary();

            $table->string('address');
            $table->string('city');
            $table->string('country');

            // Latitude and longitude (default precision 8, scale 2)
            $table->decimal('lat')->nullable();
            $table->decimal('long')->nullable();

            $table->string('name');
            $table->string('postcode');

            // Foreign key to regions.id (UUID)
            $table->foreignUuid('region_id')
                ->nullable()
                ->constrained('regions')
                ->nullOnDelete();

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
