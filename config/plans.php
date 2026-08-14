<?php

// Source of truth for plan limits (Task 35). No pricing/billing keys here
// on purpose — this project deliberately ships with no Stripe/Cashier
// integration; the `plan` column on `organizations` is set by hand or by
// `php artisan org:plan`, and this file is the only place limits live.
// `null` in a `limits` entry means unlimited for that metric.
return [
    'free' => [
        'name' => 'Free',
        'limits' => ['channels' => 2, 'posts_per_month' => 30, 'media_storage_mb' => 200],
    ],
    'standard' => [
        'name' => 'Standard',
        'limits' => ['channels' => 10, 'posts_per_month' => 500, 'media_storage_mb' => 5000],
    ],
    'pro' => [
        'name' => 'Pro',
        'limits' => ['channels' => 50, 'posts_per_month' => null, 'media_storage_mb' => 50000], // null = unlimited
    ],
];
