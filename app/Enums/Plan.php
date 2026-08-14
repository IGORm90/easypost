<?php

namespace App\Enums;

/**
 * A subscription tier, stored as `organizations.plan`. The full shape of
 * each tier (display name + limits) lives in `config/plans.php`, keyed by
 * this enum's value — this type exists so callers pass a real,
 * typo-proof case around instead of a bare string, not to duplicate any
 * limit data itself.
 *
 * No pricing/billing concept lives on this enum or anywhere near it —
 * see `App\Services\Billing\PlanRegistry`'s docblock for why.
 */
enum Plan: string
{
    case FREE = 'free';
    case STANDARD = 'standard';
    case PRO = 'pro';
}
