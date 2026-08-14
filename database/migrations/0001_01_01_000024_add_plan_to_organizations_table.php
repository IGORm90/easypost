<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Task 35: the single column a future billing system would flip.
     * Nullable, despite the `'free'` default, so tests (and any future
     * migration-repair script) can represent "no plan recorded yet"
     * explicitly and exercise `PlanRegistry::forOrganization()`'s
     * fallback rather than only ever seeing the DB default.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->enum('plan', ['free', 'standard', 'pro'])->nullable()->default('free')->after('owner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('plan');
        });
    }
};
