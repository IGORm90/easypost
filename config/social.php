<?php

use Tests\Support\FakeProvider;

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
    | Tests\Support\FakeProvider is registered under the 'fake' identifier
    | only in the testing environment (see below) — it must never appear in
    | the production list, and it is not a real network integration.
    |
    */
    'providers' => array_merge(
        [
            // Real providers land here in Tasks 14-15, e.g.:
            // 'telegram' => App\Social\Providers\Telegram\TelegramProvider::class,
        ],
        env('APP_ENV') === 'testing' ? [
            'fake' => FakeProvider::class,
        ] : [],
    ),

];
