<?php

use App\Models\Organization;
use App\Support\OrganizationContext;
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
