<?php

namespace App\Providers;

use App\Listeners\ClearOrganizationContext;
use App\Models\PostGroup;
use App\Policies\PostPolicy;
use App\Services\Auth\Socialite\OidcProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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

        // See App\Listeners\ClearOrganizationContext for why all three events.
        Event::listen(
            [JobProcessing::class, JobProcessed::class, JobFailed::class],
            ClearOrganizationContext::class,
        );

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
