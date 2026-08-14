<?php

namespace App\Enums;

/**
 * A member's role within a single organization. Stored as the `role`
 * column on `organization_user` (and mirrored on `invites`, which carries
 * the role an accepted invite will grant).
 */
enum OrganizationRole: string
{
    case SUPERADMIN = 'SUPERADMIN';
    case ADMIN = 'ADMIN';
    case USER = 'USER';

    /**
     * Roles are hierarchical, not just equal-and-separate: SUPERADMIN
     * outranks ADMIN outranks USER. This is the single source of truth for
     * that ordering — the `role:` route middleware and any future
     * "does this member satisfy requirement X" check must go through this
     * rather than comparing role strings/cases directly, so the hierarchy
     * only needs to be encoded once.
     */
    public function rank(): int
    {
        return match ($this) {
            self::SUPERADMIN => 3,
            self::ADMIN => 2,
            self::USER => 1,
        };
    }

    /**
     * True when this role satisfies a requirement of at least $role — e.g.
     * SUPERADMIN::atLeast(ADMIN) is true, USER::atLeast(ADMIN) is false.
     */
    public function atLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }
}
