<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Task 28 review ruling: a media picker/gallery (Task 29) needs pixel
     * dimensions to reserve layout space before the image itself loads —
     * that cannot be derived at read time (PostResource) without either
     * violating the "no disk reads outside upload/import time" rule or
     * re-fetching the file from S3/MinIO on every request, so it must be
     * captured once, here, alongside `size_bytes`. Both nullable: video
     * (and any future non-image type) has no meaningful "width"/"height"
     * for this column pair, and `getimagesize()` itself can fail on a
     * corrupt-but-mime-sniffed-as-image file — both are legitimate
     * "unknown", not an error, same reasoning as `thumbnail_path` being
     * nullable.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable()->after('mime_type');
            $table->unsignedInteger('height')->nullable()->after('width');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['width', 'height']);
        });
    }
};
