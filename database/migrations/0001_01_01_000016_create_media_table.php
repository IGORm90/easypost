<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Organization-scoped media library (Task 27): one row per stored
     * file, uploaded directly or imported by URL. `path`/`thumbnail_path`
     * are keys on the `media` disk (see config/filesystems.php), never a
     * local filesystem path -- the same "storage key, not a stat-able
     * path" shape `MediaDto::$path` already documents, so a row here can
     * be turned into a MediaDto (Task 28) without any conversion.
     *
     * `size_bytes` is NOT NULL: captured once, at upload/import time, by
     * MediaUploadService -- never re-derived later by reading the file
     * off the `media` disk (see that service's docblock, and
     * MediaDto::$sizeBytes's for why "read the size from disk when
     * needed" stopped being viable the moment storage moved off the
     * local filesystem).
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // Always 'media' today -- kept as an explicit column (not a
            // hardcoded literal in the model) so a future migration to a
            // different disk name, or a per-row override, does not
            // require a schema change.
            $table->string('disk')->default('media');
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            // User input (the client-supplied upload filename, or the
            // last path segment of an imported URL) -- sanitized by
            // MediaUploadService (basename() + control-character
            // stripping + length cap) before it ever reaches this
            // column, since it is echoed back in MediaResource and may
            // eventually reach a Content-Disposition header.
            $table->string('original_filename');
            // Nullable + nullOnDelete(): same reasoning as post_groups'
            // created_by -- losing the uploader's identity on user
            // deletion must not delete or block deletion of the media
            // itself (it may still be referenced by posts).
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('organization_id');
            // Supports GET /api/media?search= (Task 27: ILIKE against
            // original_filename, scoped to the current organization by
            // BelongsToOrganization's global scope -- this composite
            // index covers both predicates of that query together).
            $table->index(['organization_id', 'original_filename']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
