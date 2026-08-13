<?php

namespace App\Support;

/**
 * Holds the current tenant's organization id for the duration of a request
 * (or console/job execution once callers opt in explicitly).
 *
 * Deviation from the plan's illustrative signature: `App\Models\Organization`
 * does not exist yet (it is Task 5's deliverable), so this class cannot type
 * against it without breaking PHPStan (`class.notFound`) the moment it's
 * introduced. Holding only the id keeps this class honest about what it
 * actually has today. `get()` currently returns the id, matching `id()`;
 * once Organization exists, Task 5 (or whichever task first needs the
 * resolved model) may change `get()` to resolve and return the Organization
 * instance instead, without needing to touch `set()`/`id()`.
 */
final class OrganizationContext
{
    private static ?string $organizationId = null;

    public static function set(string $organizationId): void
    {
        self::$organizationId = $organizationId;
    }

    /**
     * Returns the current organization id.
     *
     * Named `get()` to match the interface the plan anticipates; it returns
     * the id (not an Organization instance) because that model does not
     * exist yet. See the class docblock.
     */
    public static function get(): ?string
    {
        return self::$organizationId;
    }

    public static function id(): ?string
    {
        return self::$organizationId;
    }

    /**
     * Resets the context. Used between tests and may be used by console
     * commands/queue workers that must not leak context across jobs.
     */
    public static function clear(): void
    {
        self::$organizationId = null;
    }
}
