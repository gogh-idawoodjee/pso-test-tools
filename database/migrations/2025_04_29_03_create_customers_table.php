<?php

use App\Models\User;
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
        Schema::create('customers', static function (Blueprint $table) {
            // Use UUID as primary key
            $table->uuid('id')->primary();

            $table->string('name');
            // new slug column, unique and right after name
            $table->string('slug');

            $table->string('address');
            $table->string('city');
            $table->string('country');

            // Latitude and longitude with higher precision
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('long', 10, 7)->nullable();

            $table->string('postcode');

            // Foreign key to regions.id (UUID)
            $table->foreignUuid('region_id')
                ->nullable()
                ->constrained('regions')
                ->nullOnDelete();

            // Foreign key to users.id (integer)
            $table->foreignIdFor(User::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('status');
            $table->timestamps();
            $table->index('region_id');
            $table->index('user_id');
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
