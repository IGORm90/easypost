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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_id');
            // `text`, not `string`: the `encrypted` cast on the model
            // ciphers these before they reach the database, and Laravel's
            // encrypted payload (base64 JSON envelope) is comfortably
            // longer than a raw OAuth token, so a varchar(255) column would
            // risk silent truncation.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampsTz();

            // One provider account can only ever be linked to one local
            // user — this is what SocialAccountService::findOrCreateUser()
            // relies on to recognise a returning OAuth user without a
            // second, racier existence check.
            $table->unique(['provider', 'provider_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
