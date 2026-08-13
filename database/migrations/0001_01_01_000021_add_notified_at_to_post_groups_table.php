<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 32: the "notify once" guard for `PostFailedNotification`.
     * `ScanMissedPostsJob` (via `MissedPostsService::notifyFailed()`)
     * scans for `ERROR` groups with `notified_at IS NULL` every 10
     * minutes — without this column, the same author would be re-emailed
     * on every subsequent scan pass for as long as the group stays
     * `ERROR`, which for an abandoned post is forever. Nullable
     * timestamp, not a boolean: a real "when" is worth keeping (support
     * debugging, a future digest — Task 33 — can read it directly)
     * rather than throwing that information away.
     */
    public function up(): void
    {
        Schema::table('post_groups', function (Blueprint $table) {
            $table->timestampTz('notified_at')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('post_groups', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
