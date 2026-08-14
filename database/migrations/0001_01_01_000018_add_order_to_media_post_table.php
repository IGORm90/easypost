<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Task 28: an attachment's position within a post's media list is
     * content (two images in the wrong order is a wrong post), so it
     * must be persisted explicitly rather than inferred from insertion
     * order — Postgres gives no ordering guarantee for rows read back
     * without an ORDER BY, and even if it happened to, "read back by
     * primary/insertion id" is not the same thing as "the order the
     * client sent". `order` defaults to 0 so the handful of existing
     * `media_post` writers that attach() without pivot data (Task 27's
     * MediaLibraryTest, exercising the 409-on-delete check directly
     * against the pivot) keep working unchanged.
     */
    public function up(): void
    {
        Schema::table('media_post', function (Blueprint $table) {
            $table->unsignedInteger('order')->default(0);

            // One position per post: two attachments on the same post
            // claiming the same `order` would make the read-back order
            // ambiguous again, defeating the point of this column.
            $table->unique(['post_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_post', function (Blueprint $table) {
            $table->dropUnique(['post_id', 'order']);
            $table->dropColumn('order');
        });
    }
};
