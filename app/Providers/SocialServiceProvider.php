<?php

namespace App\Providers;

use App\Social\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Binds App\Social\ProviderRegistry as a singleton. config/social.php is
 * a real config file (auto-loaded by Laravel's config bootstrapper like
 * any other file under config/), so nothing needs merging here.
 * Deliberately does not know about any specific provider — the
 * identifier => class map lives entirely in config, per the project's
 * Global Constraint that no provider-specific logic lives outside
 * app/Social/Providers.
 */
class SocialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);
    }
}
