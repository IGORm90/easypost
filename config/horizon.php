<?php

use Illuminate\Support\Str;

/*
 * Task 34. Published from the vendor stub (config/horizon.php did not
 * exist before this task — Task 30 deliberately deferred it rather than
 * ship a partial config while Horizon ran on package defaults; see that
 * task's brief) and then trimmed to only the sections this project
 * actually needs to diverge on: 'use'/'prefix' (left at vendor default —
 * a single Redis instance, single app, no need for a custom prefix),
 * 'waits', and 'environments'/'defaults', which carry the three queues
 * this task's brief specifies.
 *
 * Three queues, one process:
 *  - `publish`  — PublishPostJob only (config/... routed via the job's
 *    own `public $queue`, not `->onQueue()` at any dispatch call site —
 *    see that class). The single highest-value queue in the product: a
 *    post going out late is a customer-visible miss. Ten dedicated
 *    worker processes, always running, never shared with `default`/
 *    `mail` — that dedicated capacity IS how "prioritised" is
 *    implemented here, not a blocking queue-list order on a shared
 *    supervisor. A shared list (`queue: publish,default,mail` on one
 *    supervisor) would let a `default`/`mail` backlog starve `publish`
 *    processing if ever picked up mid-list; separate supervisors can't
 *    starve each other since each has its own dedicated processes.
 *  - `default`  — everything else that has no reason to be elsewhere:
 *    RefreshIntegrationTokenJob (enumeration, not the failure path),
 *    ScanMissedPostsJob, SendDigestEmailJob. None of these are
 *    customer-latency-sensitive the way `publish` is.
 *  - `mail`     — the four ShouldQueue notifications that actually send
 *    an email (PostFailedNotification, IntegrationBrokenNotification,
 *    VerifyEmailNotification, ResetPasswordNotification — each now sets
 *    `public $queue = 'mail'`). NOT `WeeklyDigestMail`: that Mailable
 *    deliberately does not implement ShouldQueue at all (see its own
 *    docblock) — it sends synchronously from inside SendDigestEmailJob,
 *    which is already the queue boundary. Two workers is deliberately
 *    modest: mail volume in this product is per-user-event (a broken
 *    integration, a failed post, a signup, a password reset), not a
 *    bulk broadcast list.
 *
 * `'*'` (not `'production'`/`'local'`) as the sole environment key:
 * `docker/compose.yaml`'s single `horizon` service is what this stack
 * actually runs everywhere it runs today (see that file — there is no
 * separate production compose target yet), and `ProvisioningPlan::deploy()`
 * matches environment names with `Str::is()` (wildcard-aware), so `'*'`
 * applies regardless of `APP_ENV`. Splitting into `'local'`/`'production'`
 * would invite exactly the drift this task's brief warns about — a
 * config that says 10 workers while whatever actually deploys reads a
 * different, forgotten key.
 */
return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    /*
    | `publish` gets the tightest threshold: ten dedicated workers with
    | nothing else competing for them means a wait past 30s already
    | signals real trouble (a stuck job, an under-provisioned host), not
    | ordinary queueing behind other work. `default`/`mail` keep the
    | package's own defaults' order of magnitude.
    */
    'waits' => [
        'redis:publish' => 30,
        'redis:default' => 60,
        'redis:mail' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    /*
    | Deliberately empty — verified against the real `ProvisioningPlan`
    | source, not assumed. `ProvisioningPlan::applyDefaultOptions()` does
    | `array_replace_recursive($defaults, $plan)` once per ENVIRONMENT
    | (the whole `environments.*` array), not once per supervisor within
    | it: a `defaults` keyed `supervisor-1` does not fill in missing
    | options on an existing `supervisor-publish`/`supervisor-default`/
    | `supervisor-mail` entry the way a "defaults" name suggests — it
    | merges in as a disjoint FOURTH supervisor, deployed alongside the
    | three named ones. Confirmed by construction: publishing this file
    | with the vendor stub's non-empty `defaults.supervisor-1` and
    | restarting the `horizon` container produced four running
    | supervisors (`supervisor-1` plus the three below), not three — the
    | worker-count mismatch this task's brief specifically warns about,
    | self-inflicted by copying the vendor stub's `defaults` block
    | without checking what it actually does. Each of the three
    | supervisors below is already a complete, self-sufficient option
    | set, so `defaults` has nothing left to usefully contribute.
    */
    'defaults' => [],

    'environments' => [
        '*' => [
            'supervisor-publish' => [
                'connection' => 'redis',
                'queue' => ['publish'],
                'balance' => 'auto',
                'maxProcesses' => 10,
                'tries' => 1,
                'timeout' => 90,
            ],

            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'maxProcesses' => 3,
                'tries' => 1,
                'timeout' => 60,
            ],

            'supervisor-mail' => [
                'connection' => 'redis',
                'queue' => ['mail'],
                'balance' => 'auto',
                'maxProcesses' => 2,
                'tries' => 1,
                'timeout' => 60,
            ],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
