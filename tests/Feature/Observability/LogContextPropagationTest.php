<?php

use Illuminate\Support\Facades\Context;
use Tests\Support\Jobs\RecordLogContextJob;

/*
 * LOG_PLAN.md Phase 1.3/1.7: Laravel dehydrates Context into the job
 * payload and rehydrates it on the worker, so a job dispatched during a
 * request keeps the originating request_id — proved here rather than
 * assumed, on the `sync` queue connection testing forces (phpunit.xml),
 * which still round-trips serialization exactly like a real worker.
 */
afterEach(function () {
    Context::flush();
});

it('carries request_id into a job dispatched while Context holds one', function () {
    Context::add('request_id', 'context-propagation-test-id');
    RecordLogContextJob::$observedRequestId = 'not-run';

    RecordLogContextJob::dispatch();

    expect(RecordLogContextJob::$observedRequestId)->toBe('context-propagation-test-id');
});
