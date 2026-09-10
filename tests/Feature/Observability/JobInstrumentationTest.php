<?php

use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Tests\Support\Jobs\FailingSetOrganizationContextJob;
use Tests\Support\Jobs\SetOrganizationContextJob;

/*
 * LOG_PLAN.md Phase 1.4: the source for requirement 4 (job RPS). Real
 * dispatches through the `sync` queue connection testing forces
 * (phpunit.xml), which still fires JobProcessing/JobProcessed/JobFailed
 * like a real worker — not the listener called directly — so a wiring
 * regression in AppServiceProvider::boot() fails this test too.
 *
 * Log::shouldReceive(), not Log::spy()+shouldHaveReceived() — see
 * MissedPostsTest/ImpersonationTest's identical note: the latter's
 * assertion method only exists on the Mockery spy instance
 * Facade::__callStatic() forwards to at runtime, invisible to
 * Larastan's static analysis. ->andReturnSelf() on channel('jobs') keeps
 * the chain on the Log facade's own already-typed mock rather than a
 * raw Mockery::mock() whose return type Larastan can't see through.
 */
it('emits exactly one job.processed line with a numeric duration_ms on success', function () {
    Log::shouldReceive('channel')->with('jobs')->once()->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'job.processed'
                && is_string($context['queue'])
                && $context['job'] === SetOrganizationContextJob::class
                && $context['result'] === 'ok'
                && is_int($context['duration_ms'])
                && $context['duration_ms'] >= 0;
        });

    SetOrganizationContextJob::dispatch(Organization::factory()->create());
});

it('emits job.failed and no job.processed when a job throws', function () {
    Log::shouldReceive('channel')->with('jobs')->once()->andReturnSelf();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'job.failed'
                && $context['job'] === FailingSetOrganizationContextJob::class
                && $context['result'] === 'failed'
                && is_string($context['exception']);
        });
    // No Log::shouldReceive('info') expectation is set up here — the
    // Mockery-backed facade throws on any unexpected call, so a stray
    // job.processed for a job that threw fails this test.

    try {
        FailingSetOrganizationContextJob::dispatch(Organization::factory()->create());
    } catch (RuntimeException) {
        // Expected — sync rethrows after firing JobFailed.
    }
});
