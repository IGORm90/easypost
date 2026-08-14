<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `organization_id` denormalisation — decided deliberately, not by
     * default: `posts` is reached from the calendar/API primarily
     * through its `post_groups` parent, but two things force it to carry
     * its own `organization_id` too. First, `BelongsToOrganization`
     * (the project-wide multi-tenancy boundary) scopes by a real column
     * on the model's own table — a model with no `organization_id`
     * cannot use it and would need a bespoke, easy-to-get-wrong scope
     * joining through `post_groups` on every single query. Second, and
     * more importantly, Task 30's publish job queries posts in a job
     * context where NO organization is ever in `OrganizationContext` —
     * the exact shape Task 16 already solved for `Integration` via
     * `withoutGlobalScope('organization')` by name in
     * `TokenRefreshService::dueForRefresh()`. A denormalised column lets
     * `Post` reuse that identical, already-reviewed pattern; a
     * join-through-parent design would instead require its own bespoke
     * cross-tenant query shape for exactly one job, duplicating a
     * solved problem.
     *
     * The risk denormalisation raises — the column silently drifting
     * from its group's real organization_id — is closed by the database,
     * not merely documented: `posts_organization_matches_group_fk` below
     * is a composite foreign key that makes any row whose
     * `organization_id` disagrees with its `post_group_id`'s owner
     * physically un-insertable/un-updatable.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('post_group_id', 26);
            // See this migration's class docblock for why this column
            // exists at all. The composite FK added below (not a plain
            // ->constrained() here) is what stops it from ever drifting
            // from its group's real organization_id.
            $table->char('organization_id', 26);
            // restrictOnDelete(), NOT cascadeOnDelete() — a hard delete of
            // an Integration must not silently erase every Post tied to
            // it, PUBLISHED segments and their release_id included: those
            // are exactly the records this migration's immutability
            // trigger exists to make tamper-proof, and a table-level
            // cascade would destroy them through a path the trigger
            // cannot see (it only guards UPDATE, not DELETE via a parent
            // FK). Integration itself uses SoftDeletes, so the ordinary
            // "disconnect a channel" flow never reaches this — this guard
            // is for a future prune job, a manual cleanup, or a stray
            // forceDelete(), which must fail loudly (a FK violation) and
            // force a human decision, not proceed quietly. If the same
            // remote account is later reconnected as a brand-new
            // Integration row after old posts were actually erased, Task
            // 30's dedup check (release_id) would have nothing left to
            // compare against — restricting the delete is what keeps that
            // scenario from being reachable at all.
            $table->foreignUlid('integration_id')->constrained()->restrictOnDelete();
            // Nullable self-reference: null means "root of its thread on
            // this channel". Not wrapped in ->constrained() — the real
            // FK is the composite one added below, which additionally
            // enforces that a child shares its parent's post_group_id
            // AND integration_id (a thread can never jump group or
            // channel).
            $table->char('parent_post_id', 26)->nullable();
            $table->integer('order')->default(0);
            $table->text('content');
            $table->jsonb('settings')->nullable();
            $table->string('state')->default('DRAFT');
            $table->string('release_url')->nullable();
            // The anti-duplication marker (Task 15's
            // ProviderPartialFailureException / PostResultDto contract):
            // set exactly once, by the publish pipeline (Task 30), the
            // moment a provider confirms a segment went out. See the
            // immutability trigger below — this migration does not stop
            // at "nullable column", it stops the database from ever
            // letting a set value be blanked or swapped for another.
            $table->string('release_id')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->index('post_group_id');
            $table->index('organization_id');

            // Composite target for the self-referencing parent FK below —
            // same "exact referenced column set" requirement as
            // post_groups' (organization_id, id) unique constraint.
            $table->unique(['post_group_id', 'integration_id', 'id']);

            // The chain-order invariant: no two segments of the same
            // channel within the same post group may claim the same
            // position. This must hold across the WHOLE (group, channel)
            // pair, not merely among the direct siblings of one parent —
            // a "chain" IS the ordered set of every segment sharing a
            // (post_group_id, integration_id), linked root-to-tip by
            // parent_post_id — so "unique within a chain" and "unique
            // within (post_group_id, integration_id)" are the same
            // statement.
            $table->unique(['post_group_id', 'integration_id', 'order'], 'posts_chain_order_unique');
        });

        // Composite FK: a post's organization_id must equal the
        // organization_id of the post_group it belongs to — enforced by
        // the database, not merely assumed by application code. See the
        // class docblock above for why this column is denormalised in
        // the first place.
        DB::statement(<<<'SQL'
            alter table posts
                add constraint posts_organization_matches_group_fk
                foreign key (organization_id, post_group_id)
                references post_groups (organization_id, id)
                on delete cascade
            SQL);

        // Composite self-referencing FK: a child post must share its
        // parent's post_group_id AND integration_id — a thread cannot
        // span groups or channels. NULL parent_post_id (root posts) is
        // exempt: Postgres' default MATCH SIMPLE means a composite FK is
        // not checked at all when any referencing column is NULL, which
        // is exactly the "no parent, no constraint" behaviour a root
        // post needs.
        DB::statement(<<<'SQL'
            alter table posts
                add constraint posts_parent_same_group_and_integration_fk
                foreign key (post_group_id, integration_id, parent_post_id)
                references posts (post_group_id, integration_id, id)
                on delete cascade
            SQL);

        // Anti-duplication guarantee, enforced at the database layer:
        // once release_id is set on a row, no UPDATE may change or clear
        // it. This is what makes "never re-send a segment that already
        // holds a release_id" (Task 30's core invariant) a property the
        // database itself refuses to violate, not merely a rule the
        // publish job is trusted to honour.
        //
        // Deliberately does NOT also require "state = PUBLISHED implies
        // release_id is set" — the ordering between setting release_id
        // and transitioning state (same statement? which first?) belongs
        // to Task 30's implementation, not to this schema, and a
        // same-transaction CHECK constraint here could reject a
        // perfectly correct multi-statement update sequence that Task 30
        // hasn't been designed yet.
        //
        // `create or replace function` / `create or replace trigger`
        // (the latter supported since Postgres 14, confirmed against
        // this project's Postgres 17), not plain `create` — required
        // because `migrate:fresh` on Postgres compiles to
        // `drop table ... cascade` per table (see
        // PostgresGrammar::compileDropAllTables()), which drops the
        // TRIGGER (owned by the table) but NOT the standalone FUNCTION
        // it calls. A plain `create function` here made a second
        // `migrate:fresh` run fail with "function already exists" —
        // caught by re-running this migration a second time while
        // building this task, not a theoretical risk.
        // The trigger raises with an explicit, custom SQLSTATE
        // ('PS001' — see Post::RELEASE_ID_IMMUTABLE_SQLSTATE, the single
        // documented source of truth for this value) rather than letting
        // PL/pgSQL default to the generic 'P0001' every unqualified
        // `raise exception` shares. Task 30 needs a reliable way to
        // detect "this failed because of the immutability guard"
        // specifically (Illuminate\Database\QueryException::getCode()
        // surfaces the PDO/SQLSTATE code) without either catching
        // QueryException broadly (which would also swallow unrelated
        // failures) or string-matching the human-readable message
        // (which breaks the moment someone rewords it). Keeping the code
        // as a PHP constant next to this migration, instead of only in
        // this SQL, is what keeps the two from drifting apart.
        DB::unprepared(<<<'SQL'
            create or replace function posts_prevent_release_id_change() returns trigger as $$
            begin
                if old.release_id is not null and new.release_id is distinct from old.release_id then
                    raise exception 'release_id is immutable once set (post %)', old.id
                        using errcode = 'PS001';
                end if;
                return new;
            end;
            $$ language plpgsql;

            create or replace trigger posts_release_id_immutable
                before update on posts
                for each row
                execute function posts_prevent_release_id_change();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
        DB::unprepared('drop function if exists posts_prevent_release_id_change() cascade');
    }
};
