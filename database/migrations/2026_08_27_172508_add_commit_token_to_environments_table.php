<?php

use App\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->string('commit_token')->nullable()->unique()->after('password');
        });

        Environment::withoutGlobalScopes()->whereNull('commit_token')->each(
            fn (Environment $environment) => $environment->update(['commit_token' => Str::random(40)])
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn('commit_token');
        });
    }
};
