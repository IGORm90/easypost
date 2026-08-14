<?php

use App\Models\Organization;
use App\Support\OrganizationContext;
use Tests\Support\Jobs\FailingSetOrganizationContextJob;
use Tests\Support\Jobs\RecordOrganizationContextJob;
use Tests\Support\Jobs\SetOrganizationContextJob;

/*
 * Horizon worker processes are long-lived, so OrganizationContext (a plain
 * static) survives across jobs on the same worker unless something resets
 * it at the job boundary. This dispatches two real jobs back-to-back
 * through the actual queue connection (`sync` in testing — see
 * phpunit.xml — which still fires JobProcessing/JobProcessed like a real
 * worker would), rather than calling the listener directly, so it proves
 * the wiring (AppServiceProvider -> event -> listener) actually runs, not
 * just that the listener class works in isolation.
 */
afterEach(function () {
    OrganizationContext::clear();
});

it('clears the organization context between queued jobs even when a job forgets to', function () {
    RecordOrganizationContextJob::$observedOrganizationId = 'not-run';

    SetOrganizationContextJob::dispatch(Organization::factory()->create());
    RecordOrganizationContextJob::dispatch();

    expect(RecordOrganizationContextJob::$observedOrganizationId)->toBeNull();
});

/*
 * Final review, batch 4 (tenancy minors). The test above passes with any
 * ONE of the three registrations in place — on `sync`, JobProcessing and
 * JobProcessed both fire between those two dispatches — so deleting two
 * of the three left it green. The three tests below each isolate exactly
 * one event, so removing any single registration now fails a test that
 * names it.
 */
it('clears a context left over from before the queue ever ran (JobProcessing)', function () {
    // Nothing has been dispatched yet, so no JobProcessed/JobFailed for
    // this context exists or ever will: it is what a worker killed
    // mid-job leaves behind. Only the JobProcessing registration runs
    // before the recording job's handle().
    OrganizationContext::set(Organization::factory()->create());
    RecordOrganizationContextJob::$observedOrganizationId = 'not-run';

    RecordOrganizationContextJob::dispatch();

    expect(RecordOrganizationContextJob::$observedOrganizationId)->toBeNull();
});

it('clears the context a completed job set, before anything else runs (JobProcessed)', function () {
    // No second dispatch, so JobProcessing never fires again — if the
    // context is gone once dispatch() returns, JobProcessed is what took
    // it away.
    SetOrganizationContextJob::dispatch(Organization::factory()->create());

    expect(OrganizationContext::get())->toBeNull();
});

it('clears the context a job that threw left behind (JobFailed)', function () {
    // The one case JobProcessed cannot cover: the job never completed.
    // `sync` rethrows after raising the failure event, so the throw is
    // caught here and the assertion is about what survived it.
    try {
        FailingSetOrganizationContextJob::dispatch(Organization::factory()->create());
    } catch (RuntimeException) {
        // Expected — the point is what the listener did, not the throw.
    }

    expect(OrganizationContext::get())->toBeNull();
});
