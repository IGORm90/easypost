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
        Schema::create('integration_time_slots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('integration_id')->constrained()->cascadeOnDelete();
            // Minutes elapsed since the start of the week (Monday 00:00 UTC),
            // 0-10079 — a compact, timezone-agnostic way to store a weekly
            // recurring posting slot. Interpretation/scheduling logic is
            // owned by whichever later task consumes this table; this
            // migration only owns storage.
            $table->unsignedInteger('minutes_from_week_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_time_slots');
    }
};
