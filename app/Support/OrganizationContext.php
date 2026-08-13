<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Holds the current tenant's Organization for the duration of a request
 * (or console/job execution once callers opt in explicitly).
 *
 * Task 5 restores the plan's intended API now that `App\Models\Organization`
 * exists: `set()` takes the resolved Organization instance (not just its
 * id) and `get()` returns it directly. Holding the whole model — set once
 * by whoever resolved it (e.g. `ResolveOrganization` middleware, which
 * already has to query it to verify membership) — means `id()`, which
 * `BelongsToOrganization`'s global scope calls on every single query, never
 * has to hit the database itself.
 */
final class OrganizationContext
{
    private static ?Organization $organization = null;

    public static function set(Organization $organization): void
    {
        self::$organization = $organization;
    }

    /**
     * Returns the current organization, or null if none has been resolved
     * for this request/job.
     */
    public static function get(): ?Organization
    {
        return self::$organization;
    }

    public static function id(): ?string
    {
        return self::$organization?->id;
    }

    /**
     * Resets the context. Used between tests and may be used by console
     * commands/queue workers that must not leak context across jobs.
     */
    public static function clear(): void
    {
        self::$organization = null;
    }
}
