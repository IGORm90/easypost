<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `shortlink_provider` is an organization-level setting ("Настройки
     * организации: короткие ссылки" per the plan) read later by the
     * link-shortening feature (out of scope for this task, which only
     * exposes it through PUT /api/organizations/current). Nullable and
     * free-form: no provider enum exists yet, and validating against one
     * here would force this task to invent a provider list a later task
     * owns.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('shortlink_provider')->nullable()->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('shortlink_provider');
        });
    }
};
