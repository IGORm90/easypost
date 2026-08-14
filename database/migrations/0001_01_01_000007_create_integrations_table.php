<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider_identifier');
            // The account/channel id on the remote network (AuthResultDto::$internalId)
            // — NOT this row's own id. Together with organization_id and
            // provider_identifier this is the upsert key IntegrationService::connect()
            // uses to recognise "reconnecting the same channel" vs. "a new one".
            $table->string('internal_id');
            $table->string('name');
            $table->string('picture')->nullable();
            // Provider-specific profile identifier/URL (e.g. a Mastodon
            // handle or a Slack workspace URL) — opaque to this layer,
            // display-only, distinct from `settings` (which drives
            // provider behaviour, not just display).
            $table->string('profile')->nullable();
            // `text`, not `string`: the `encrypted` cast ciphers these
            // before they reach the database (Laravel's encrypted payload
            // — base64 JSON envelope — is comfortably longer than a raw
            // OAuth token), matching the precedent set by
            // social_accounts.access_token/refresh_token.
            $table->text('token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->jsonb('settings')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('group_name')->nullable();
            $table->boolean('disabled')->default(false);
            $table->boolean('in_between_steps')->default(false);
            $table->boolean('refresh_needed')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();

            // Plain (non-unique) index: backs IntegrationService::connect()'s
            // withTrashed() lookup. The actual uniqueness guarantee lives in
            // the partial unique index created below, not here.
            $table->index(['organization_id', 'provider_identifier', 'internal_id']);
        });

        // Partial unique index instead of a plain unique constraint: a plain
        // unique index would count soft-deleted rows too, so reconnecting
        // the same channel after a soft delete (IntegrationService::connect()
        // restores the trashed row in place) would be fine, but any future
        // code path that ever inserts a fresh row while an old soft-deleted
        // one for the same key still exists would violate it. Scoping the
        // uniqueness to `deleted_at IS NULL` is what actually encodes "at
        // most one *live* row per (organization, provider, internal_id)" —
        // which is the real invariant guarding against a duplicate live
        // integration ever existing, including under a race between two
        // concurrent connect() calls that both pass the application-level
        // check.
        DB::statement(
            'create unique index integrations_org_provider_internal_live_unique '
            .'on integrations (organization_id, provider_identifier, internal_id) '
            .'where deleted_at is null'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
