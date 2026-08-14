<?php

namespace App\Services\Billing\Exceptions;

use App\Enums\PlanLimit;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown by `PlanLimitService::check()` the moment an action would push an
 * organization's usage of some `PlanLimit` metric past its plan's
 * configured maximum.
 *
 * Self-rendering (via `render()`, a method Laravel's exception handler
 * calls automatically when present) rather than something every caller
 * has to remember to catch and map: Task 35 only builds the registry, the
 * service and the console command — the actual enforcement call sites
 * (Task 36's middleware/controllers) don't exist yet, so the 402 JSON
 * shape `{message, limit, current, max}` has to be an intrinsic property
 * of the exception itself, not something a not-yet-written controller
 * would supply.
 *
 * `message` is deliberately actionable (Task 36 requirement): with no
 * billing/upgrade flow in this application at all, a bare "you've hit
 * your limit" leaves the recipient with no next step, so `describe()`
 * below names the one thing that is actually true of every organization
 * on this codebase and actually within THEIR OWN power to do — free up
 * existing usage (disconnect a channel, delete media) or wait for the
 * monthly reset (posts only). No `upgrade_url` in the JSON body: there
 * is nothing to link to.
 *
 * Round 1 review, Important 4: an earlier revision of `describe()` also
 * offered "ask an organization admin to move to a plan with more room"
 * (`php artisan org:plan`). That is an ops-only console command, not a
 * lever this application exposes to any customer, admin or not — there
 * is no plan-change UI anywhere in this MVP. Pointing a customer at an
 * action nobody who receives this message can actually take is worse
 * than not naming an action at all, so that clause is gone from every
 * case below, not reworded.
 */
class PlanLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly PlanLimit $limit,
        public readonly int $current,
        public readonly ?int $max,
    ) {
        parent::__construct(self::describe($limit, $current, $max));
    }

    private static function describe(PlanLimit $limit, int $current, ?int $max): string
    {
        return match ($limit) {
            PlanLimit::CHANNELS => "You've reached your plan's channel limit ({$current}/{$max}). Disconnect a channel you no longer use to free up room.",
            PlanLimit::POSTS_PER_MONTH => "You've reached your plan's monthly post limit ({$current}/{$max}). Wait for next month's reset to schedule more.",
            PlanLimit::MEDIA_STORAGE_MB => "You've reached your plan's media storage limit ({$current}/{$max} MB). Delete unused files from the media library to free up room.",
        };
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'limit' => $this->limit->value,
            'current' => $this->current,
            'max' => $this->max,
        ], 402);
    }
}
