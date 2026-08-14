<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the last cross-tenant hole the database itself was not watching
 * (final review, batch 4 — tenancy minors).
 *
 * `posts` already carries a composite FK proving a post's
 * `organization_id` equals its post group's — see
 * `create_posts_table`'s `posts_organization_matches_group_fk`. The same
 * proof was missing for the OTHER thing a post points at: its channel.
 * `posts.integration_id` had only a plain single-column FK, so nothing
 * below the application layer forbade a row whose `organization_id` is
 * one tenant and whose `integration_id` names another tenant's channel —
 * exactly the shape that ends with a stored credential posting content
 * it was never given.
 *
 * The review recorded this as blocked, because a composite FK must
 * reference an exactly-matching unique constraint and `integrations` had
 * no `(organization_id, id)` one. That block is what this migration
 * lifts: the unique constraint first, then the FK — the same two-step
 * `post_groups`/`posts` already uses, not a new idea.
 *
 * Soft deletes are unaffected. `integrations` is soft-deleted, so a
 * disconnected channel's ROW remains and the FK stays satisfied; nothing
 * here changes what `restrictOnDelete()` on the original FK means for a
 * genuine hard delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table): void {
            // Redundant as a uniqueness claim — `id` alone is already the
            // primary key — and that is fine: its purpose is to be the
            // exact referenced column set the composite FK below requires,
            // identical in role to post_groups' own (organization_id, id).
            $table->unique(['organization_id', 'id']);
        });

        DB::statement(<<<'SQL'
            alter table posts
                add constraint posts_organization_matches_integration_fk
                foreign key (organization_id, integration_id)
                references integrations (organization_id, id)
            SQL);
    }

    public function down(): void
    {
        // Dropped in the reverse order they were added: the FK depends on
        // the unique constraint, so Postgres refuses to drop the latter
        // while the former still references it.
        DB::statement('alter table posts drop constraint posts_organization_matches_integration_fk');

        Schema::table('integrations', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'id']);
        });
    }
};
