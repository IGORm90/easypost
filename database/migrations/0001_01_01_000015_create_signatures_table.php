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
        Schema::create('signatures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
        });

        // Partial unique index: at most one DEFAULT signature per
        // organization — mirroring the precedent set by integrations'
        // partial unique index (Task 12). A plain
        // unique(organization_id, is_default) would ALSO forbid more
        // than one non-default signature per org, which is not the
        // invariant wanted; scoping to `where is_default = true` is what
        // actually encodes "at most one default", leaving any number of
        // non-default signatures untouched.
        DB::statement(
            'create unique index signatures_one_default_per_org '
            .'on signatures (organization_id) where is_default = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
