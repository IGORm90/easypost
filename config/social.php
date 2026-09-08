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

];
