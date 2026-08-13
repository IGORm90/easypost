<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A single connected social-network account/channel for an organization
 * (e.g. one Telegram channel, one Bluesky account).
 *
 * Placeholder introduced by Task 11 solely so that
 * `App\Social\Contracts\SocialProviderInterface` and friends have a real
 * class to type-hint against (`post(Integration $integration, ...)`,
 * `refresh(Integration $i)`) — PHP resolves type hints lazily so this
 * would work at runtime even without this file, but PHPStan level 6
 * cannot analyse a reference to a class that doesn't exist yet, and other
 * Task-11 code (ProviderRegistry, the fake provider, tests) needs a
 * concrete type to construct against.
 *
 * Deliberately minimal: no migration, no fillable/casts, no encryption,
 * no relations. Task 12 owns the `integrations` table, the encrypted
 * token casts, the tenant scope and the service layer around this model
 * and is expected to flesh this class out — it must NOT be renamed or
 * moved, since the provider contracts (and every provider implementation
 * from Task 14 onward) are written against `App\Models\Integration`.
 */
class Integration extends Model
{
    use HasUlids;
}
