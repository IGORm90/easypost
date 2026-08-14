<?php

namespace Tests\Support\Jobs;

use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Test-only job that sets OrganizationContext and then throws — the case
 * `JobProcessed` never sees, because the job never finished. Only the
 * `JobFailed` registration in `AppServiceProvider::boot()` can clear the
 * context it leaves behind.
 *
 * Exists so that registration is pinned by a test of its own: with all
 * three events wired to the same listener, a single "context does not
 * cross a job boundary" test passes even if two of the three are deleted.
 * See tests/Feature/QueueClearsOrganizationContextTest.php.
 */
class FailingSetOrganizationContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Organization $organization) {}

    public function handle(): void
    {
        OrganizationContext::set($this->organization);

        throw new RuntimeException('this job dies with a tenant still in context');
    }
}
