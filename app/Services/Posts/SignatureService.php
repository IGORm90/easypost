<?php

namespace App\Services\Posts;

use App\Models\Organization;
use App\Models\Signature;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;

/**
 * Owns every write to `Signature` (Task 23), including the "at most one
 * default per organization" invariant.
 *
 * The `signatures_one_default_per_org` partial unique index (Task 18's
 * migration) is the invariant's ultimate backstop — it guarantees the bad
 * state (two defaults) can never persist, full stop, regardless of any
 * bug here, a future direct DB write, or an `artisan tinker` mistake. But
 * relying on it ALONE, from application code, would mean a naive
 * "clear the others, then set this one" under genuine concurrency either:
 *
 *  - races and lets the second writer's INSERT/UPDATE hit the unique
 *    index and throw, surfacing as a raw 500 to whichever request lost,
 *    even though "someone else changed the default a moment ago" is a
 *    perfectly ordinary, recoverable outcome that deserves a clean
 *    response; or
 *  - in the "no default exists yet" case specifically, races with
 *    *nothing to lock*: two concurrent creates that both intend to become
 *    the org's very first default might both pass a
 *    `where('is_default', true)` pre-check (finding zero rows, nothing to
 *    serialize against) and only collide at INSERT time.
 *
 * `clearDefault()` below closes both gaps the same way MembershipService's
 * `guardLastSuperadmin()` closes its own last-SUPERADMIN race (Task 9): it
 * takes `SELECT ... FOR UPDATE` on a row that is GUARANTEED to already
 * exist for this organization regardless of how many signatures it has —
 * the Organization row itself, not a signature row — inside the same
 * DB::transaction() that goes on to clear the old default and persist the
 * new one. A second, genuinely concurrent write for the same organization
 * blocks on that lock until the first transaction commits (or rolls back),
 * then re-reads `is_default` state as it now stands and proceeds from
 * there — so every concurrent request succeeds in program order, with
 * exactly one default surviving, and the unique index is never actually
 * hit in the process (it remains the backstop, not the mechanism).
 */
class SignatureService
{
    /**
     * @param  array{content: string, is_default: bool}  $data
     */
    public function create(array $data): Signature
    {
        return DB::transaction(function () use ($data): Signature {
            if ($data['is_default']) {
                $this->clearDefault();
            }

            return Signature::create($data);
        });
    }

    /**
     * @param  array{content: string, is_default: bool}  $data
     */
    public function update(Signature $signature, array $data): Signature
    {
        return DB::transaction(function () use ($signature, $data): Signature {
            if ($data['is_default']) {
                $this->clearDefault($signature->id);
            }

            $signature->fill($data);
            $signature->save();

            return $signature;
        });
    }

    public function delete(Signature $signature): void
    {
        $signature->delete();
    }

    /**
     * Must only ever be called from inside the same DB::transaction()
     * that goes on to persist the new default (both call sites above do
     * this) — `lockForUpdate()` only serializes concurrent callers for
     * the lifetime of an explicit transaction; used standalone it locks
     * nothing beyond this one query. See this class's docblock for why
     * the lock is taken on the Organization row rather than on any
     * signature row.
     */
    private function clearDefault(?string $exceptId = null): void
    {
        $organization = OrganizationContext::get();

        if ($organization === null) {
            return;
        }

        Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

        Signature::query()
            ->where('is_default', true)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }
}
