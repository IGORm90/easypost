<?php

namespace App\Providers;

use App\Listeners\ClearOrganizationContext;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
        // See App\Listeners\ClearOrganizationContext for why all three events.
        Event::listen(
            [JobProcessing::class, JobProcessed::class, JobFailed::class],
            ClearOrganizationContext::class,
        );
    }
}
