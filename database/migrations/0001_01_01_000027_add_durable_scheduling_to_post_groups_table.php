<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable-scheduling rewrite: Postgres becomes the source of truth
     * for the schedule instead of a delayed Redis job. `PostState` grows
     * a `SCHEDULED` case sitting between `DRAFT` and the now-narrower
     * `QUEUE` (claimed, a `PublishPostJob` genuinely exists) — see that
     * enum's docblock and `App\Console\Commands\DispatchDuePostsCommand`.
     *
     * `dispatch_attempts` is the only new column this migration adds:
     * `last_dispatched_at` (`..._000022`) and `queue_version`
     * (`..._000020`) already exist and are reused as-is — the durable
     * scheduling brief's "dispatched_at"/"queue_version" map onto them
     * directly, so adding differently-named duplicates would just be
     * confusing. Incremented on every claim, by the dispatcher AND by
     * `MissedPostsService`'s recovery re-claim — it is what lets the
     * recovery path cap retries instead of re-dispatching a broken
     * schedule forever (see `config/publishing.php`'s
     * `max_dispatch_attempts`).
     *
     * `CONCURRENTLY` for both indexes, and `$withinTransaction = false`
     * to allow it — this table is written on every post save/publish in
     * production and must not be locked for the duration of an index
     * build. Both indexes are partial (`WHERE state = ...`): the
     * dispatcher and the recovery scanner only ever query one state each,
     * and a partial index stays small regardless of how large
     * `post_groups` grows, unlike an index over the whole table.
     *
     * Post-review fix: this migration used to ALSO backfill every
     * existing `QUEUE` row (and its posts) to `SCHEDULED` here, in the
     * same `up()`. That broke the hard "additive only" migration rule —
     * `docs/DEPLOYMENT.md`'s own deploy sequence runs migrations BEFORE
     * restarting `php`/`horizon`/`scheduler`, so there was a real window
     * where the database had already been rewritten to the new state
     * model while every running process still had OLD code loaded,
     * expecting `QUEUE` to mean "a delayed job exists" — an old delayed
     * job firing in that window would find its row `SCHEDULED`, no-op,
     * and nothing yet running (`posts:dispatch-due` doesn't exist in the
     * old code) would ever pick it back up. This migration is now purely
     * additive — schema only — and safe to deploy alone, in any order.
     * The backfill itself moved to `php artisan posts:backfill-durable-scheduling`
     * (`App\Console\Commands\BackfillDurableSchedulingCommand`), run
     * manually, once, AFTER the new code (this release's dispatcher and
     * state machine) is already live — see that command's docblock and
     * the rollout order in `docs/DEPLOYMENT.md`.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('post_groups', function (Blueprint $table) {
            $table->unsignedSmallInteger('dispatch_attempts')->default(0)->after('last_dispatched_at');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY idx_post_groups_due
              ON post_groups (publish_date) WHERE state = 'SCHEDULED'
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY idx_post_groups_stuck
              ON post_groups (last_dispatched_at) WHERE state = 'QUEUE'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_post_groups_stuck');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_post_groups_due');

        Schema::table('post_groups', function (Blueprint $table) {
            $table->dropColumn('dispatch_attempts');
        });
    }
};
