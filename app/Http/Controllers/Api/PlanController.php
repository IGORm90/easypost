<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Billing\PlanLimitService;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * `GET /api/organizations/current/plan` — what this organization's plan
 * allows and how much of it is already used.
 *
 * Task 36 specified `PlanLimitService::usage()` as being "for the UI",
 * and then nothing exposed it: a user could be told they had hit a limit
 * and had no way to see how close they were before that. Final review,
 * batch 4 (coverage and gates).
 *
 * Plain membership, no `role:` requirement. How much of your own
 * organization's quota is spent is not privileged information relative to
 * the person whose posting it constrains — and the numbers are already
 * derivable by anyone who can list channels, posts and media, which every
 * member can.
 *
 * On the architecture rule this appears to break: `PlanLimitControllerIsolationTest`
 * forbids controllers from touching `PlanLimitService`, and this
 * controller is on that test's short allow-list deliberately. The rule
 * exists so a limit is never ENFORCED outside the transaction that
 * performs the INSERT — a controller-level `check()` is a decision made
 * where it cannot be made atomically. Reading current usage decides
 * nothing and guards nothing; it is a plain read, which is exactly what a
 * controller is for. See that test's own note.
 */
class PlanController extends Controller
{
    public function __construct(private readonly PlanLimitService $limits) {}

    public function show(): JsonResponse
    {
        $organization = OrganizationContext::get();

        if (! $organization instanceof Organization) {
            // Unreachable through the routing (`organization` middleware
            // sets this before the controller runs) — thrown rather than
            // defaulted so a future caller that skips the middleware
            // fails loudly instead of reading someone else's quota.
            throw new RuntimeException('PlanController requires OrganizationContext to be set.');
        }

        return response()->json([
            'plan' => $organization->plan,
            'usage' => $this->limits->usage($organization),
        ]);
    }
}
