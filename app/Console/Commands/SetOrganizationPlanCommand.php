<?php

namespace App\Console\Commands;

use App\Enums\Plan;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Task 35's manual billing lever: this project ships with no payments
 * integration at all (no Stripe, no Cashier), so moving an organization
 * onto a paid tier — or back to Free — is an operator running this
 * command, not a webhook. See `App\Services\Billing\PlanRegistry` for
 * where `organizations.plan` is read back.
 */
class SetOrganizationPlanCommand extends Command
{
    protected $signature = 'org:plan {organization : Organization ID} {plan : One of free, standard, pro}';

    protected $description = "Set an organization's plan.";

    public function handle(): int
    {
        $organization = Organization::query()->find($this->argument('organization'));

        if (! $organization instanceof Organization) {
            $this->error("No organization found with id [{$this->argument('organization')}].");

            return self::FAILURE;
        }

        $plan = Plan::tryFrom((string) $this->argument('plan'));

        if ($plan === null) {
            $allowed = implode(', ', array_map(fn (Plan $case) => $case->value, Plan::cases()));
            $this->error("Invalid plan [{$this->argument('plan')}]. Must be one of: {$allowed}.");

            return self::FAILURE;
        }

        $organization->update(['plan' => $plan]);

        $this->info("Organization [{$organization->id}] is now on the [{$plan->value}] plan.");

        return self::SUCCESS;
    }
}
