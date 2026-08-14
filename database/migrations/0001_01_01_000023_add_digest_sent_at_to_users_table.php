<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 33's idempotency guard for the weekly digest — see
     * `DigestService::claim()`'s docblock for the full reasoning. Nullable
     * timestamp (not a boolean, not a "which ISO week" integer): the exact
     * instant a digest last actually sent is what the claim's cooldown
     * window compares against, and it is also useful on its own for support
     * debugging ("did this user ever get a digest, and when").
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('digest_sent_at')->nullable()->after('email_notifications');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('digest_sent_at');
        });
    }
};
