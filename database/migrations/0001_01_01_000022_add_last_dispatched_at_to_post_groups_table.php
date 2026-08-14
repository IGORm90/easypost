<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 32 review, round 2: the leaked-lock bound in
     * `MissedPostsService::lockCouldStillBeLegitimate()` needs to know
     * WHEN a `PublishPostJob` dispatch actually acquired its
     * `ShouldBeUnique` lock — that happens at DISPATCH time, not at
     * `publish_date` (see `PostGroupService::dispatchPublishIfQueued()`'s
     * docblock: a reschedule to "now", a manual retry, or the scanner's
     * own redispatch all dispatch well AFTER `publish_date`, sometimes
     * long after). `publish_date` alone was the wrong anchor — this
     * column is the right one, written every time `PublishPostJob` is
     * actually dispatched (`PostGroupService::dispatchPublishIfQueued()`
     * and `MissedPostsService::dispatchRedispatch()`, the only two call
     * sites in the codebase).
     *
     * Nullable: a `DRAFT` group, or one that has never been queued, has
     * never been dispatched for at all.
     */
    public function up(): void
    {
        Schema::table('post_groups', function (Blueprint $table) {
            $table->timestampTz('last_dispatched_at')->nullable()->after('queue_version');
        });
    }

    public function down(): void
    {
        Schema::table('post_groups', function (Blueprint $table) {
            $table->dropColumn('last_dispatched_at');
        });
    }
};
