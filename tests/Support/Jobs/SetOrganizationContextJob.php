<?php

namespace Tests\Support\Jobs;

use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Test-only job that sets OrganizationContext and deliberately does NOT
 * clear it — simulating a job author who forgot to. Used to prove the
 * queue-event listener (not the job itself) is what resets the context
 * before the next job runs. See tests/Feature/QueueClearsOrganizationContextTest.php.
 *
 * Takes an Organization (SerializesModels re-fetches it from the database
 * on the worker side, exactly like a real job would) rather than a bare id,
 * matching OrganizationContext::set()'s Task 5 signature.
 */
class SetOrganizationContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Organization $organization) {}

    public function handle(): void
    {
        OrganizationContext::set($this->organization);
    }
}
