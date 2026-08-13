<?php

namespace Tests\Support\Jobs;

use App\Support\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Test-only job that records whatever OrganizationContext holds when it
 * runs. Dispatched right after SetOrganizationContextJob to prove the
 * context does not survive a job boundary on the same worker process.
 */
class RecordOrganizationContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static ?string $observedOrganizationId = 'not-run';

    public function handle(): void
    {
        self::$observedOrganizationId = OrganizationContext::id();
    }
}
