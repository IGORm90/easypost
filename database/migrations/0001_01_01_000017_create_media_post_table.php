<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Plain many-to-many pivot between `media` and `posts`, no own
     * id/model -- same shape as `post_group_tag`. Schema only: Task 27
     * lays this table so DELETE /api/media/{id}'s "409 if used by an
     * unpublished post" check (across the whole organization, not just
     * the caller's own posts -- MediaController queries `posts` joined
     * through this pivot, unfiltered by created_by) has something to
     * query against even though nothing writes to this table until Task
     * 28 ("attach media to a post") exists. Until then this table is
     * always empty and the 409 branch is simply unreachable in practice
     * -- covered by MediaLibraryTest once Task 28 lands, not by this
     * task's own tests.
     *
     * References `posts` (the per-channel segment), not `post_groups`:
     * each channel segment carries its own media selection (different
     * networks may want different crops/images for the same calendar
     * entry), and `posts.state` (not `post_groups.state`) is what
     * "unpublished" means for this check -- both share PostState, but a
     * segment's own state is the accurate per-attachment answer to "is
     * this specific use of the media still unpublished".
     */
    public function up(): void
    {
        Schema::create('media_post', function (Blueprint $table) {
            $table->foreignUlid('media_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('post_id')->constrained('posts')->cascadeOnDelete();

            $table->primary(['media_id', 'post_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_post');
    }
};
