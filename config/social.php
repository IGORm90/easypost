<?php

use App\Social\Providers\BlueskyProvider;
use App\Social\Providers\DiscordProvider;
use App\Social\Providers\MastodonProvider;
use App\Social\Providers\SlackProvider;
use App\Social\Providers\TelegramProvider;

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
    | any specific network. Real providers (Telegram, Mastodon, Bluesky,
    | Discord, Slack) are added here starting Task 14.
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
        'mastodon' => MastodonProvider::class,
        'bluesky' => BlueskyProvider::class,
        'discord' => DiscordProvider::class,
        'slack' => SlackProvider::class,
    ],

];
