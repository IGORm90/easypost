<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Plain many-to-many pivot, no own id/model: tenant scoping is
     * inherited transitively through post_groups (itself
     * organization-scoped) — a row here is only ever reachable through
     * an already tenant-checked PostGroup, the same reasoning
     * IntegrationTimeSlot documents for its relationship to Integration.
     */
    public function up(): void
    {
        Schema::create('post_group_tag', function (Blueprint $table) {
            $table->foreignUlid('post_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['post_group_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_group_tag');
    }
};
