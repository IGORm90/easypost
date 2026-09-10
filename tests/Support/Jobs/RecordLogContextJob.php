<?php

namespace Tests\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

/**
 * Test-only job that records whatever Context holds when it runs.
 * Dispatched during a request to prove Context::add('request_id', ...) —
 * set by AttachLogContext — survives the job boundary. Laravel dehydrates
 * Context into the job payload automatically; this proves it rather than
 * assuming it (LOG_PLAN.md Phase 1.3/1.7).
 */
class RecordLogContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static ?string $observedRequestId = 'not-run';

    public function handle(): void
    {
        self::$observedRequestId = Context::get('request_id');
    }
}
