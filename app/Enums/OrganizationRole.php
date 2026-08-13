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
}
