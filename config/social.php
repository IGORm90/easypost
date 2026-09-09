<?php

use App\Social\Providers\BlueskyProvider;
use App\Social\Providers\FacebookPageProvider;
use App\Social\Providers\InstagramProvider;
use App\Social\Providers\RedditProvider;
use App\Social\Providers\TelegramProvider;
use App\Social\Providers\ThreadsProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Social Providers
    |--------------------------------------------------------------------------
    |
    | Identifier => class map consumed by App\Social\ProviderRegistry. Every
    | class listed here must implement App\Social\Contracts\SocialProviderInterface.
    | This is the ONLY place provider identifiers are wired to their
    | implementation — App\Social\ProviderRegistry itself has no knowledge of
    | any specific network. The networks this install ships are listed
    | below, and that list is the whole of it: an Integration row naming
    | anything else is treated as a channel whose network is gone (see
    | PostValidationService and PublishService), never as an error here.
    |
    | This file ships to production, so it deliberately contains no
    | reference to Tests\Support\FakeProvider (an autoload-dev-only
    | class) or to any other test fixture. The 'fake' identifier used by
    | tests is wired separately in Tests\TestCase::setUp() via a config()
    | override, which only ever runs inside the test process.
    |
    */
    'providers' => [
        'telegram' => TelegramProvider::class,
        'bluesky' => BlueskyProvider::class,
        'reddit' => RedditProvider::class,
        'facebook-page' => FacebookPageProvider::class,
        'instagram' => InstagramProvider::class,
        'meta-threads' => ThreadsProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-provider publish rate limits (QUEUE.md Task 7)
    |--------------------------------------------------------------------------
    |
    | identifier => ['max' => int, 'decay_seconds' => int] — a shared budget
    | ACROSS EVERY ORGANIZATION on this installation for that one network,
    | enforced by App\Services\Posts\PublishService::enforceProviderThrottle()
    | immediately before the provider's own API is called. An identifier
    | with no entry here is unthrottled — every provider starts that way.
    |
    | These are deliberately conservative PLACEHOLDERS, not measured
    | ceilings — tune each one to sit BELOW the real platform limit once
    | you have one on file for it, to leave headroom for analytics or any
    | other API traffic sharing the same budget. A limit set exactly at
    | the platform's own published ceiling leaves no margin at all.
    |
    | Belongs here, not in config/publishing.php — that file is
    | deliberately provider-agnostic (see its own docblock); this file
    | already declares itself the ONLY place provider identifiers are
    | wired, which a rate-limit table keyed by identifier is a natural
    | extension of. tests/Unit/Architecture/ProviderIsolationTest only
    | greps app/, never config/, so a provider identifier appearing here
    | does not trip it.
    |
    */
    'rate_limits' => [
        'reddit' => ['max' => 50, 'decay_seconds' => 60],
        'telegram' => ['max' => 25, 'decay_seconds' => 1],
        'bluesky' => ['max' => 100, 'decay_seconds' => 300],
        'facebook-page' => ['max' => 200, 'decay_seconds' => 3600],
        'instagram' => ['max' => 200, 'decay_seconds' => 3600],
        'meta-threads' => ['max' => 200, 'decay_seconds' => 3600],
    ],

];
