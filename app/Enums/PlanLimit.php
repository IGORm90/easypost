<?php

namespace App\Enums;

/**
 * One of the three metrics a plan caps (Task 35). Each case's value is
 * exactly the key it corresponds to under `limits` in `config/plans.php`
 * — `PlanRegistry::forOrganization($org)['limits'][$limit->value]` is how
 * `PlanLimitService` reads the configured maximum for a given case.
 */
enum PlanLimit: string
{
    /** Live (non-soft-deleted) `Integration` rows connected to the organization. */
    case CHANNELS = 'channels';

    /** `PostGroup`s created in the current UTC calendar month. */
    case POSTS_PER_MONTH = 'posts_per_month';

    /** Sum of `Media.size_bytes` for the organization, in megabytes. */
    case MEDIA_STORAGE_MB = 'media_storage_mb';
}
