<?php

namespace App\Http\Requests\Concerns;

use App\Models\Tag;
use Illuminate\Support\Str;

/**
 * Shared case-insensitive uniqueness check for StoreTagRequest/
 * UpdateTagRequest. `Tag::query()` is already organization-scoped via
 * BelongsToOrganization's global scope, so this never needs to reference
 * `organization_id` itself.
 *
 * A plain `Rule::unique('tags', 'name')` would NOT catch "Launch" vs
 * "launch" — Postgres' default collation is case-sensitive, and Laravel's
 * unique rule compares the raw column value, not `lower(name)`. The
 * `tags_org_name_ci_unique` functional index (see the tags migration)
 * enforces the case-insensitive rule at the database layer regardless,
 * but letting a collision reach that far would surface as a raw 500 from
 * an unhandled QueryException instead of a clean 422 — this closure
 * rule exists purely to fail early, in the exact same shape as it, before
 * the request reaches the database at all.
 */
trait ValidatesTagUniqueness
{
    protected function uniqueNameRule(?string $ignoreId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId): void {
            if (! is_string($value)) {
                return;
            }

            $exists = Tag::query()
                ->whereRaw('lower(name) = ?', [Str::lower($value)])
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();

            if ($exists) {
                $fail('A tag with this name already exists.');
            }
        };
    }
}
