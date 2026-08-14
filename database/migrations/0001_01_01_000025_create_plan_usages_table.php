<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Task 35: a cached counter, not the ground truth. Only
     * `PlanLimit::POSTS_PER_MONTH` is tracked here — `CHANNELS` and
     * `MEDIA_STORAGE_MB` are cheap enough to count on demand straight
     * from `integrations`/`media` (see `PlanLimitService`'s docblock),
     * so caching them here would only introduce a second place they
     * could drift from reality. `metric` is still a column (not a
     * dedicated `posts_usages` table) so a future usage-tracked metric
     * doesn't need its own migration.
     *
     * `period` is the UTC calendar month this row's `count` belongs to,
     * formatted `Y-m` (e.g. `2026-08`) by `PlanLimitService` — a new
     * month means a new row, which is how the counter "resets" without
     * any cron/cleanup job: an old period's row is simply never read or
     * incremented again (and can be pruned later without affecting
     * anything live).
     *
     * The unique index is the backstop for the row `PlanLimitService`'s
     * atomic claim relies on — see that class for why the actual
     * exclusion mechanism is an `organizations` row lock, not this
     * index.
     */
    public function up(): void
    {
        Schema::create('plan_usages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 40);
            $table->string('period', 7);
            $table->unsignedInteger('count')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'metric', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_usages');
    }
};
