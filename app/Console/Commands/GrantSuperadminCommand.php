<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Makes an existing user a platform superadmin (`users.is_superadmin`: Pulse,
 * Log Viewer, the admin API). Not OrganizationRole::SUPERADMIN, which every
 * organization's creator already holds.
 *
 * Registration is closed on a production install (REGISTRATION_ENABLED=false),
 * so the first operator signs up through the door opened on purpose and runs
 * this once. It replaces a `tinker` one-liner in a production shell, which
 * leaves no trace. `is_superadmin` is deliberately not mass-assignable, hence
 * forceFill(): no request can ever reach this path.
 */
class GrantSuperadminCommand extends Command
{
    protected $signature = 'user:superadmin {email : Email of an existing user}';

    protected $description = 'Make an existing user a platform superadmin.';

    public function handle(): int
    {
        // Emails are stored lowercase (RegisterRequest::prepareForValidation).
        $email = Str::lower((string) $this->argument('email'));

        $user = User::query()->firstWhere('email', $email);

        if (! $user instanceof User) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        if ($user->is_superadmin) {
            $this->info("User [{$user->id}] is already a superadmin; nothing changed.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_superadmin' => true])->save();

        // By id, never by email: addresses stay out of the logs (AGENTS.md).
        Log::info('superadmin.granted', ['user_id' => $user->id]);

        $this->info("User [{$user->id}] is now a superadmin.");

        return self::SUCCESS;
    }
}
