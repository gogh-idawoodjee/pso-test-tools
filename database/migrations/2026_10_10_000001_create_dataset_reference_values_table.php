<?php

use App\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the user-owned base data tables with one dataset-scoped table
     * and re-parents customers and tasks onto datasets. Existing rows in the
     * dropped tables are test data with no value and are not carried over.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['tasks', 'customers', 'task_types', 'skills', 'regions', 'slot_usage_rules', 'appointment_templates'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        Schema::table('datasets', static function (Blueprint $table) {
            $table->dateTime('last_synced_at')->nullable();
            $table->json('last_sync_summary')->nullable();
        });

        Schema::create('dataset_reference_values', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->string('entity_type', 32);
            $table->string('pso_id', 64);
            $table->string('label');
            $table->json('attributes')->nullable();
            $table->string('origin', 16);
            $table->dateTime('last_seen_at')->nullable();
            $table->boolean('is_locally_modified')->default(false);
            $table->timestamps();

            $table->unique(['dataset_id', 'entity_type', 'pso_id']);
            $table->index(['dataset_id', 'entity_type']);
        });

        Schema::create('customers', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('address');
            $table->string('city');
            $table->string('country');
            $table->string('postcode');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('long', 10, 7)->nullable();
            $table->foreignUuid('region_id')->nullable()->constrained('dataset_reference_values')->nullOnDelete();
            $table->string('status');
            $table->timestamps();

            $table->index('region_id');
        });

        Schema::create('tasks', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('task_type_id')->nullable()->constrained('dataset_reference_values')->nullOnDelete();
            $table->string('friendly_id');
            $table->decimal('base_value', 12, 2)->nullable();
            $table->dateTime('appt_window_start')->nullable();
            $table->dateTime('appt_window_finish')->nullable();
            $table->integer('duration')->nullable();
            $table->string('status')->default(TaskStatus::IGNORE->value);
            $table->timestamps();

            $table->unique(['dataset_id', 'friendly_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('dataset_reference_values');
        Schema::table('datasets', static function (Blueprint $table) {
            $table->dropColumn(['last_synced_at', 'last_sync_summary']);
        });
    }
};
