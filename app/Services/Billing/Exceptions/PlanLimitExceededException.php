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
 */
class PlanLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly PlanLimit $limit,
        public readonly int $current,
        public readonly ?int $max,
    ) {
        parent::__construct("The {$limit->value} limit for this organization's plan has been reached.");
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
