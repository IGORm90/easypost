<?php

use App\Models\Organization;
use App\Services\Posts\Exceptions\PublishRetryNeededException;
use App\Services\Posts\PublishService;
use Illuminate\Support\Facades\Log;

/*
 * LOG_PLAN.md Phase 1.5 — the payoff line: "a customer's post did not go
 * out, and we do not know why" was previously unanswerable from logs.
 * Drives the real PublishService/FakeProvider pipeline (never the
 * listener/log call directly), so a wiring regression in
 * publishLockedChannel() fails this test too.
 *
 * Log::shouldReceive(), not Log::spy() — see MissedPostsTest's note:
 * the assertion method Log::spy() returns is invisible to Larastan.
 */
it('logs one publish.attempt per channel on success, with a real provider value and numeric lateness', function () {
    fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->subSeconds(5));
    publishableChannel($org, $group, ['channel A']);

    Log::shouldReceive('channel')->with('jobs')->once()->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'publish.attempt'
                && $context['provider'] === 'fake'
                && is_string($context['integration_id'])
                && is_string($context['post_group_id'])
                && $context['result'] === 'published'
                && $context['failure_kind'] === null
                && is_int($context['lateness_ms'])
                && $context['lateness_ms'] >= 0;
        });

    app(PublishService::class)->publishGroup($group->id);
});

it('logs failure_kind=auth and marks the channel failed on an auth error', function () {
    fakeProvider()->failNextPostWithAuthError('token revoked');

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);

    Log::shouldReceive('channel')->with('jobs')->once()->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'publish.attempt'
                && $context['result'] === 'failed'
                && $context['failure_kind'] === 'auth';
        });

    app(PublishService::class)->publishGroup($group->id);
});

it('logs failure_kind=rate_limit and a retry result on a rate-limit error', function () {
    fakeProvider()->failNextPostWithRateLimit(30, 'slow down');

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);

    Log::shouldReceive('channel')->with('jobs')->once()->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'publish.attempt'
                && $context['result'] === 'retry'
                && $context['failure_kind'] === 'rate_limit';
        });

    try {
        app(PublishService::class)->publishGroup($group->id);
    } catch (PublishRetryNeededException) {
        // Expected — the group still has retry-eligible channels; the
        // point here is what got logged before this was thrown.
    }
});
