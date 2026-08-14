<?php

use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Models\TenantStub;

/*
 * These tests exercise BelongsToOrganization against a throwaway
 * table/model (see tests/Support/Models/TenantStub.php) so the trait
 * itself is what's under test, not any particular tenant-owned domain
 * model. `OrganizationContext::set()` (Task 5) requires a real Organization
 * instance, but only ever reads its `id` — so these use unsaved,
 * forceFill()'d instances rather than persisting real organizations rows,
 * keeping this file independent of the `organizations` table and free of
 * side effects on it (this suite has no RefreshDatabase, see tests/Pest.php).
 */
function orgWithId(string $id): Organization
{
    return (new Organization)->forceFill(['id' => $id]);
}

beforeEach(function () {
    // Defensive: drop any leftover table from a previous run that died
    // between beforeEach and afterEach (e.g. a fatal error mid-test).
    Schema::dropIfExists('tenant_stubs');

    Schema::create('tenant_stubs', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->char('organization_id', 26)->nullable();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('tenant_stubs');
    OrganizationContext::clear();
});

it('scopes queries to the current organization', function () {
    $orgA = (string) Str::ulid();
    $orgB = (string) Str::ulid();

    TenantStub::create(['organization_id' => $orgA, 'name' => 'a']);
    TenantStub::create(['organization_id' => $orgB, 'name' => 'b']);

    OrganizationContext::set(orgWithId($orgA));

    expect(TenantStub::pluck('name')->all())->toBe(['a']);
});

it('auto-fills organization_id on create', function () {
    $orgA = (string) Str::ulid();

    OrganizationContext::set(orgWithId($orgA));

    expect(TenantStub::create(['name' => 'x'])->organization_id)->toBe($orgA);
});

it('returns nothing when no organization is set in context, instead of leaking all tenants data', function () {
    $orgA = (string) Str::ulid();
    $orgB = (string) Str::ulid();

    TenantStub::create(['organization_id' => $orgA, 'name' => 'a']);
    TenantStub::create(['organization_id' => $orgB, 'name' => 'b']);

    // No OrganizationContext::set() call: context is empty.
    expect(TenantStub::pluck('name')->all())->toBe([]);
});

it('lets withoutGlobalScopes bypass the tenant scope', function () {
    $orgA = (string) Str::ulid();
    $orgB = (string) Str::ulid();

    TenantStub::create(['organization_id' => $orgA, 'name' => 'a']);
    TenantStub::create(['organization_id' => $orgB, 'name' => 'b']);

    OrganizationContext::set(orgWithId($orgA));

    $names = TenantStub::withoutGlobalScopes()->pluck('name')->all();
    sort($names);

    expect($names)->toBe(['a', 'b']);
});

it('does not overwrite an explicitly-set organization_id on create', function () {
    $orgA = (string) Str::ulid();
    $orgB = (string) Str::ulid();

    OrganizationContext::set(orgWithId($orgA));

    $stub = TenantStub::create(['name' => 'x', 'organization_id' => $orgB]);

    expect($stub->organization_id)->toBe($orgB);
});

it('does not let a mass update from organization B touch organization A rows', function () {
    $orgA = (string) Str::ulid();
    $orgB = (string) Str::ulid();

    $a = TenantStub::create(['organization_id' => $orgA, 'name' => 'a']);
    TenantStub::create(['organization_id' => $orgB, 'name' => 'b']);

    OrganizationContext::set(orgWithId($orgB));

    // A blanket update with no WHERE clause of its own — the global scope
    // is the only thing standing between this and cross-tenant writes.
    TenantStub::query()->update(['name' => 'hijacked']);

    expect($a->refresh()->name)->toBe('a');
    expect(TenantStub::withoutGlobalScopes()->where('id', $a->id)->value('name'))->toBe('a');
});

it('does not let a mass delete from organization B touch organization A rows', function () {
    $orgA = (string) Str::ulid();
    $orgB = (string) Str::ulid();

    $a = TenantStub::create(['organization_id' => $orgA, 'name' => 'a']);
    TenantStub::create(['organization_id' => $orgB, 'name' => 'b']);

    OrganizationContext::set(orgWithId($orgB));

    TenantStub::query()->delete();

    expect(TenantStub::withoutGlobalScopes()->whereKey($a->id)->exists())->toBeTrue();
});

it('does not let destroy() from organization B remove an organization A row by id', function () {
    $orgA = (string) Str::ulid();
    $orgB = (string) Str::ulid();

    $a = TenantStub::create(['organization_id' => $orgA, 'name' => 'a']);

    OrganizationContext::set(orgWithId($orgB));

    TenantStub::destroy($a->id);

    expect(TenantStub::withoutGlobalScopes()->whereKey($a->id)->exists())->toBeTrue();
});
