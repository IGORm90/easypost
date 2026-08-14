<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('post_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('state')->default('DRAFT');
            // Nullable: a DRAFT group has no scheduled slot yet. Only
            // QUEUE (and beyond) requires one, and that rule belongs to
            // application code (PostStateMachine / Task 19's validation
            // service), not to a NOT NULL constraint this migration
            // cannot safely assume holds for every state.
            $table->timestampTz('publish_date')->nullable();
            // Nullable + nullOnDelete(), NOT cascadeOnDelete(): offboarding
            // a user must not erase their entire publish history —
            // cascadeOnDelete() here would delete every group they ever
            // created and cascade onward to its posts and comments,
            // PUBLISHED ones included. Losing the author's identity while
            // keeping the published record is strictly better than losing
            // both, and the author is worth preserving where possible but
            // not at the cost of blocking user deletion outright.
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error')->nullable();
            // Unguessable, nullable-unique — makes a post group publicly
            // readable via Task 22's future share endpoint once set.
            // Generating the value (a cryptographically random string)
            // is deliberately left to Task 22, which owns the endpoint
            // that mints one; this migration only reserves the column
            // and its uniqueness guarantee.
            //
            // Nullable + unique behaves correctly on Postgres: every NULL
            // is treated as distinct from every other NULL by a unique
            // index (the ANSI SQL default, confirmed directly against
            // this project's Postgres 17 in the schema test), so any
            // number of groups may simultaneously have no share token.
            $table->string('share_token')->nullable()->unique();
            $table->timestampsTz();

            // Composite target for posts' denormalised organization_id
            // (see the posts migration). Required in addition to the
            // primary key: Postgres composite foreign keys must reference
            // a unique constraint on EXACTLY the referenced column set,
            // not merely a superset of it, so (organization_id, id) needs
            // its own unique constraint even though id alone is already
            // unique via the primary key.
            $table->unique(['organization_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_groups');
    }
};
