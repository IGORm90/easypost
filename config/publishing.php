<?php

/**
 * Durable-scheduling tunables. See `app/Console/Commands/DispatchDuePostsCommand.php`
 * and `app/Services/Posts/MissedPostsService.php` for how each value is
 * used. Deliberately provider-agnostic: no provider identifier belongs
 * in this file — `tests/Unit/Architecture/ProviderIsolationTest` greps
 * every quoted string under `app/` for provider names, and a
 * provider-keyed limits array here would be exactly the pattern that
 * test forbids.
 */
return [

    /*
     * How far ahead of `now()` `posts:dispatch-due` claims a `SCHEDULED`
     * group. Not zero: a per-minute cron tick that only claimed rows
     * with publish_date <= now() would let a post scheduled at, say,
     * 12:00:30 wait until the 12:01:00 tick — a needless up-to-60s delay
     * on top of the job's own dispatch/network latency. 90s covers one
     * full tick's worth of jitter either side.
     */
    'dispatch_lookahead_seconds' => (int) env('PUBLISH_DISPATCH_LOOKAHEAD', 90),

    /*
     * Per-claim-query batch size, and a hard cap on how many batches one
     * command invocation will run. Together they bound one dispatch
     * pass's worst case to dispatch_batch_size * dispatch_max_batches
     * jobs, so a large backlog (e.g. after an extended outage) cannot
     * turn a single per-minute tick into an unbounded dispatch burst.
     */
    'dispatch_batch_size' => 500,
    'dispatch_max_batches' => 20,

    /*
     * How long a group may sit at QUEUE (claimed, a PublishPostJob
     * dispatched) with no result before MissedPostsService redispatches
     * it, unconditionally — this IS the whole ≤5-minute recovery
     * guarantee, together with the scanner's own `->everyFiveMinutes()`
     * cadence (routes/console.php). There is deliberately no separate
     * "is this still a legitimate retry" check gating that decision —
     * see `MissedPostsService`'s class docblock for why a probe/wait
     * step of any length made this guarantee impossible to keep, and why
     * redispatching unconditionally is safe (not merely simpler) now
     * that `PublishPostJob::uniqueId()` is version-aware.
     */
    'stuck_after_seconds' => (int) env('PUBLISH_STUCK_AFTER', 300),

    /*
     * Once a group's dispatch_attempts reaches this count, MissedPostsService
     * stops re-dispatching it and marks it failed instead. An infinitely
     * retried post is a bug, not a transient failure — it must surface to
     * the owner rather than loop forever.
     */
    'max_dispatch_attempts' => 5,

    /*
     * Deterministic spread applied to a group's publish_date at
     * scheduling time (PostGroupService::resolvePublishDate()), meaning
     * +/- this many seconds. Never applied to a "publish now" save.
     * Prevents every tenant who picked the same round number (e.g.
     * 12:00) from producing a simultaneous burst of publishes that
     * exhausts a shared per-provider rate limit for everyone at once.
     */
    'jitter_seconds' => (int) env('PUBLISH_JITTER_SECONDS', 120),

    /*
     * How many days ahead of expiry TokenRefreshService::dueForRefresh()
     * proactively refreshes an integration's token, instead of waiting
     * for a publish attempt to fail first. Provider-agnostic (unlike
     * `config/social.php`'s `rate_limits`) — every refreshable provider
     * shares this one window. Meta/LinkedIn-class tokens commonly live
     * ~60 days; 7 days of lead time means tenants who all connected in
     * the same week don't all expire — and all get swept up for refresh
     * — in the same single hourly pass.
     */
    'token_refresh_window_days' => (int) env('TOKEN_REFRESH_WINDOW_DAYS', 7),

];
