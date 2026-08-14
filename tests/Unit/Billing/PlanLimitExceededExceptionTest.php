<?php

use App\Enums\PlanLimit;
use App\Services\Billing\Exceptions\PlanLimitExceededException;

/*
 * Task 36 fix round 1, Important 4: the pre-fix message ended with "...or
 * ask an organization admin to move to a plan with more X" — a lever this
 * codebase does not expose to any customer (`php artisan org:plan` is an
 * ops-only console command, and there is no billing/plan-change UI in this
 * MVP at all). Every message must instead point at something the customer
 * reading it can actually do themselves: disconnect a channel, delete
 * media, or wait for the monthly reset.
 */
it('never mentions an organization admin or asking anyone else', function (PlanLimit $limit) {
    $exception = new PlanLimitExceededException($limit, 2, 2);

    expect($exception->getMessage())->not->toContain('admin');
    expect($exception->getMessage())->not->toContain('ask ');
})->with([
    'channels' => [PlanLimit::CHANNELS],
    'posts_per_month' => [PlanLimit::POSTS_PER_MONTH],
    'media_storage_mb' => [PlanLimit::MEDIA_STORAGE_MB],
]);

it('tells the customer the specific thing they themselves can do for each limit', function () {
    expect((new PlanLimitExceededException(PlanLimit::CHANNELS, 2, 2))->getMessage())->toContain('Disconnect a channel');
    expect((new PlanLimitExceededException(PlanLimit::POSTS_PER_MONTH, 30, 30))->getMessage())->toContain('Wait for next month');
    expect((new PlanLimitExceededException(PlanLimit::MEDIA_STORAGE_MB, 200, 200))->getMessage())->toContain('Delete unused files');
});
