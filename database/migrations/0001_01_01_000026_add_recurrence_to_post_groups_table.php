<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Block 4: recurring publication. A repeating post is not a series of
     * rows created up front — the successor is created only after its
     * predecessor actually publishes (see RecurrenceService), so these
     * five columns describe a rule plus one bookkeeping mark, never a
     * schedule.
     *
     *  - `repeat_unit` + `repeat_interval`: the rule itself. A null
     *    `repeat_unit` is the single, canonical "this post does not
     *    repeat" — every reader tests that column and nothing else.
     *  - `repeat_until`: mandatory whenever a unit is set (enforced in
     *    ValidatesPostChains, not here — a NOT NULL constraint cannot be
     *    conditional on another column without a CHECK, and the rule is
     *    a product rule, not a storage one). An endless series would
     *    quietly consume the organization's monthly post quota forever.
     *  - `repeat_parent_id`: every occurrence points at the FIRST post of
     *    the series, never at its immediate predecessor, so the whole
     *    series is one query (`id = ? or repeat_parent_id = ?`) rather
     *    than a recursive walk. The root's own value stays null.
     *  - `repeat_spawned_at`: "this occurrence has already produced its
     *    successor". PublishPostJob is re-executed as a matter of course
     *    (Horizon retry, Redis redelivery), and `PUBLISHED` being
     *    terminal does NOT stop a second run from creating a second
     *    successor — a re-run publishes nothing twice, but it would
     *    happily spawn twice. RecurrenceService claims this column with
     *    a conditional UPDATE, exactly as MissedPostsService::notifyOne()
     *    claims `notified_at`.
     */
    public function up(): void
    {
        Schema::table('post_groups', function (Blueprint $table) {
            $table->string('repeat_unit')->nullable()->after('publish_date');
            $table->unsignedSmallInteger('repeat_interval')->nullable()->after('repeat_unit');
            $table->timestampTz('repeat_until')->nullable()->after('repeat_interval');
            // nullOnDelete(), NOT cascadeOnDelete(): the same reasoning
            // `created_by` carries in the original post_groups migration.
            // Deleting the first post of a series must not take the rest
            // of the series with it — losing the link between
            // occurrences is survivable, losing published occurrences is
            // not. In practice a published root cannot be deleted at all
            // (PostGroupService::guardEditable()), which makes this a
            // guard against the paths that bypass the service, not a
            // routine one.
            $table->foreignUlid('repeat_parent_id')->nullable()->after('repeat_until')
                ->constrained('post_groups')->nullOnDelete();
            $table->timestampTz('repeat_spawned_at')->nullable()->after('repeat_parent_id');

            // The second line of defence behind RecurrenceService's
            // `repeat_spawned_at` claim: two occurrences of one series
            // can never occupy the same instant, whatever the
            // application above believes. Nullable + unique behaves
            // correctly on Postgres — every NULL is distinct from every
            // other NULL in a unique index — so the vast majority of
            // rows, which do not belong to any series
            // (`repeat_parent_id IS NULL`), are left entirely
            // unconstrained. Same property the `share_token` unique
            // index relies on, verified against this project's Postgres
            // 17 in PostSchemaTest.
            $table->unique(['repeat_parent_id', 'publish_date']);
        });
    }

    /**
     * CI runs `migrate:rollback --step=100`, so this path is executed on
     * every build rather than merely written down. The unique index is
     * dropped explicitly and BEFORE the columns, and the foreign key via
     * `dropConstrainedForeignId()` — dropping the column alone would
     * leave Postgres to decide the order for us.
     */
    public function down(): void
    {
        Schema::table('post_groups', function (Blueprint $table) {
            $table->dropUnique(['repeat_parent_id', 'publish_date']);
            $table->dropConstrainedForeignId('repeat_parent_id');
            $table->dropColumn(['repeat_unit', 'repeat_interval', 'repeat_until', 'repeat_spawned_at']);
        });
    }
};
