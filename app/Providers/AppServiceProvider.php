<?php

namespace App\Providers;

use App\Listeners\ClearOrganizationContext;
use App\Listeners\LogJobLifecycle;
use App\Listeners\LogUniqueJobSkipped;
use App\Models\PostGroup;
use App\Models\User;
use App\Policies\PostPolicy;
use App\Services\Auth\Socialite\OidcProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Explicit binding, not naming-convention auto-discovery: this
        // policy is named `PostPolicy` per the task-20 brief, not
        // `PostGroupPolicy`, which is what Laravel's App\Models\X ->
        // App\Policies\XPolicy convention would look for on its own.
        Gate::policy(PostGroup::class, PostPolicy::class);

        // The one gate behind every platform dashboard (Horizon, and Pulse
        // and Log Viewer once installed). They show every organization's
        // jobs and logs, so access is the platform-wide `is_superadmin`
        // flag, never a role inside one organization. `?User` lets a
        // guest reach the callback and be refused explicitly.
        Gate::define('viewMonitoring', fn (?User $user) => $user?->is_superadmin === true);

        // LOG_PLAN.md Phase 1.4 — requirement 4 (job RPS/duration).
        // Registered before ClearOrganizationContext below so a future
        // org-derived field on the log line still sees a live context,
        // though today it doesn't touch OrganizationContext at all.
        Event::listen(JobProcessing::class, [LogJobLifecycle::class, 'handleProcessing']);
        Event::listen(JobProcessed::class, [LogJobLifecycle::class, 'handleProcessed']);
        Event::listen(JobFailed::class, [LogJobLifecycle::class, 'handleFailed']);

        // See App\Listeners\ClearOrganizationContext for why all three events.
        Event::listen(
            [JobProcessing::class, JobProcessed::class, JobFailed::class],
            ClearOrganizationContext::class,
        );

        // See App\Listeners\LogUniqueJobSkipped: a swallowed ShouldBeUnique
        // dispatch (PublishPostJob's lock, or any future unique job's)
        // must never be invisible.
        Event::listen(UniqueJobSkipped::class, LogUniqueJobSkipped::class);

        // laravel/socialite ships no generic OpenID Connect driver (only
        // provider-specific ones), so "oidc" is registered here against
        // App\Services\Auth\Socialite\OidcProvider — see that class's
        // docblock for why.
        Socialite::extend('oidc', function (Application $app) {
            /** @var array<string, mixed> $config */
            $config = $app['config']['services.oidc'];

            return new OidcProvider(
                $app['request'],
                $config['client_id'],
                $config['client_secret'],
                $config['redirect'],
                $config['base_url'],
            );
        });
    }
}
