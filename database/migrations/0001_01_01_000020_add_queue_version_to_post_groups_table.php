<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 31: cancellation-by-version for `PublishPostJob`. Every time
     * `PostGroupService` (re)dispatches the job for a group that just
     * (re-)entered `QUEUE` — create, update, updateDate, retry — it
     * increments this column and hands the new value to the job. The job
     * carries the version it was dispatched with and compares it, inside
     * `PublishService::lockGroupIfQueued()`'s own `lockForUpdate()`
     * transaction, against the group's CURRENT value at the moment it
     * tries to act. A mismatch means a strictly newer generation has
     * since superseded this dispatch (a reschedule, most commonly), and
     * the job exits without acting — see PublishService's class docblock
     * for the full reasoning this complements (the due-date grace check
     * alone does not catch every case: a group can be rescheduled to a
     * date that has ALSO already passed by the time a stale job fires).
     *
     * `unsignedInteger`, not a ulid/uuid: this is a plain monotonically
     * increasing counter local to one group, never compared across
     * groups or exposed as an identifier — an integer default value (0)
     * and a simple `increment()` are all this needs.
     */
    public function up(): void
    {
        Schema::table('post_groups', function (Blueprint $table) {
            $table->unsignedInteger('queue_version')->default(0)->after('publish_date');
        });
    }

    public function down(): void
    {
        Schema::table('post_groups', function (Blueprint $table) {
            $table->dropColumn('queue_version');
        });
    }
};
