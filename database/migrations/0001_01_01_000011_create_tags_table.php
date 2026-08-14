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
        Schema::create('tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color');
            $table->timestampsTz();
        });

        // Functional unique index on lower(name), NOT a plain
        // unique(organization_id, name) Blueprint constraint: Postgres'
        // default collation is case-sensitive, so a plain unique index
        // on `name` would let "Launch" and "launch" coexist as two
        // distinct tags inside one organization — a papercut in the
        // calendar's tag filter that users would not understand. This is
        // what actually prevents "Launch" / "launch" / "Launch" (a
        // second, accidental create) from existing as three distinct
        // tags; a name collision within a tenant, case aside, is
        // data-entry noise, not a legitimate second tag.
        DB::statement(
            'create unique index tags_org_name_ci_unique '
            .'on tags (organization_id, lower(name))'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
