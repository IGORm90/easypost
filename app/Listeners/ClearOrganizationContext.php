<?php

namespace App\Listeners;

use App\Support\OrganizationContext;

/**
 * Clears the tenant context around every queued job.
 *
 * Horizon worker processes are long-lived: OrganizationContext is a plain
 * static, so a job that sets it and forgets to (or dies before it can)
 * call OrganizationContext::clear() would leak that organization into the
 * next job the same worker picks up — silently, since nothing about a
 * mis-scoped query fails loudly on its own.
 *
 * Registered in AppServiceProvider::boot() against JobProcessing,
 * JobProcessed and JobFailed so the context cannot cross a job boundary
 * either direction:
 * - JobProcessing clears whatever a previous job may have left behind
 *   before this job's own code runs, even if that previous job never
 *   reached JobProcessed/JobFailed (e.g. the worker was killed mid-job).
 * - JobProcessed/JobFailed clear whatever this job itself set, so it can
 *   never leak forward even if the job's author forgot to call clear().
 */
class ClearOrganizationContext
{
    public function handle(): void
    {
        OrganizationContext::clear();
    }
}
