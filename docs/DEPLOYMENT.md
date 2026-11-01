# Deployment and operations

## Topology

Ten default long-running services plus one one-shot init container
(`docker/compose.yaml`), all sharing one Compose network:

- **nginx** — reverse proxy in front of PHP-FPM, serves `public/`, terminates
  HTTP. `client_max_body_size` is set to `108M` (matching `php.ini`'s
  `post_max_size`), not `100M` (the actual per-file cap), because nginx
  counts the whole multipart body including boundaries — see
  `docker/nginx/default.conf`'s comment.
- **php** — PHP-FPM, serves the API.
- **horizon** — runs `php artisan horizon`, the queue worker for
  `PublishPostJob` and every other queued job (welcome emails, reconnect
  notices, etc.).
- **scheduler** — runs `php artisan schedule:run` every 60 seconds (token
  refresh sweeps, digest emails, and — durable-scheduling rewrite —
  `posts:dispatch-due`, the per-minute claim that turns a `SCHEDULED` post
  into a real `PublishPostJob` once it's actually due; see
  `App\Enums\PostState`'s docblock and `DueDispatchService`). This
  container being down does not lose any scheduled post — Postgres, not
  Redis, is the durable record — it only delays publishing until the
  container comes back, by however long it was down.
- **pulse** — runs `php artisan pulse:check`, the only process that samples
  this host's CPU, memory and disk for Pulse's Servers card. Nothing else
  runs that recorder, so with this container down the card goes stale while
  the rest of Pulse keeps working. `PULSE_SERVER_NAME` is pinned (default
  `easypost`): the fallback is the container hostname, which changes on every
  recreate and would leave a stale server behind. The php image installs
  `procps` for this container: Pulse reads CPU from `top`, and without it the
  card records 0% with no error anywhere but the container log.
- **node** — runs the Vite dev server (`npm run dev`) for the SPA frontend,
  reachable at `http://localhost:5173`; also where the README's frontend
  commands (`npx vitest run`, `npm run build`) are meant to be run.
- **postgres**, **redis** — primary datastore and queue/cache/session
  backend.
- **minio** — S3-compatible object storage for the `media` disk. A
  `minio-init` one-shot container creates the `media` bucket and applies a
  `GetObject`-only anonymous read policy before php/horizon/scheduler are
  allowed to start (`condition: service_completed_successfully`) — a bucket
  that doesn't exist yet means every media upload fails from the very first
  request, which is exactly the failure mode this dependency exists to
  prevent.
- **mailpit** — catches every outbound email in dev (verification, invites,
  reconnect notices) at `http://localhost:8025`; nothing is ever delivered
  to a real inbox locally.

`php`, `horizon`, and `scheduler` all build from the same image
(`docker/php/Dockerfile`) and bind-mount the repository, so `vendor/` is
never baked into the image — a fresh `composer install` (`make setup`) is
required after cloning, and after any dependency change.

## Health, limits and restarts

Every long-running service has a healthcheck, a `mem_limit` and
`restart: unless-stopped`, and every service uses the shared `x-logging`
rotation (`10m` x 3). `ComposeServicesTest` asserts all of it from
`docker/compose.yaml`, because nothing runs the file in CI.

| Service | Healthy means | `mem_limit` |
| --- | --- | --- |
| nginx | `GET /up` through nginx, FastCGI and a booted Laravel | 64m |
| php | the `php-fpm` master process exists | 512m |
| horizon | `horizon:status` exits 0 (1 = paused, 2 = no master) | 1280m |
| scheduler | `/tmp/scheduler-heartbeat` touched in the last 3 min | 128m |
| pulse | a `pulse:check` process exists | 128m |
| postgres | `pg_isready` | 512m |
| redis | `PING` answers `PONG` | 256m |
| minio | `/minio/health/live` | 384m |
| mailpit | `/mailpit readyz` | 64m |

The limits add up to about **3.3 GB**, not the ~2.7 GB an earlier plan
assumed, because that plan sized Horizon for 6-7 workers and this stack runs 15
(10 publish, 3 default, 2 mail; ~730 MiB idle). On a 4 GB host that leaves
little for the OS. The lever is `config/horizon.php`'s process counts; when you
change them, change `horizon`'s `mem_limit` too (workers x
`horizon.memory_limit`, and `ComposeServicesTest` fails if you forget).
`php` at 512m is enough for web workers, but two simultaneous large media
uploads can reach it, and then Docker kills the whole container, not one
request.

An unhealthy container is only **reported**: Docker does not restart on a
failed healthcheck. `restart: unless-stopped` restarts a container whose
process exited, and it does not act on `docker kill` or `docker stop`, which
Docker treats as a deliberate stop. `php`, `horizon`, `scheduler` and `pulse`
start only once Postgres and Redis report healthy.

Two details that are easy to undo by accident:

- **Scheduler.** The loop runs `schedule:run && touch /tmp/scheduler-heartbeat`,
  so the heartbeat goes stale when the loop dies *and* when `schedule:run`
  keeps failing or hangs. A repeatedly failing scheduled task therefore shows
  as unhealthy; that is intended.
- **Pulse.** The check is `pgrep -f 'pulse[:]check'`. A Docker healthcheck runs
  as `sh -c "<test>"`, whose own command line contains the pattern, so an
  unescaped `pgrep -f pulse:check` matches itself and is healthy forever.

**Redis** runs `--maxmemory 200mb --maxmemory-policy noeviction --appendonly
yes` on the `redisdata` volume. Under memory pressure it refuses writes
(loudly) instead of evicting queued jobs, and the append-only file lets the
queue survive `docker compose up --force-recreate`. Without the volume the
file would be discarded with the container. If Redis ever hits 200mb, enqueues
start failing: check Horizon's completed-job retention
(`config/horizon.php`, 24 h) first.

**Horizon** has `stop_grace_period: 180s`, longer than the slowest supervisor
timeout (90 s), so a deploy lets a running publish finish. Docker's 10 s
default would cut it mid-flight, and an interrupted publish is how a post gets
published twice. The `low`-priority jobs that can still be cut must be
idempotent.

## Logging

Application logs go to **stdout/stderr as JSON** — the container runtime
captures them (`docker logs`), which is also what any future log shipper
(CloudWatch, whatever) reads from. A fourth channel, `viewer`, writes the
same events as plain-text files for Log Viewer (see "Log Viewer" below).
Four channels (`config/logging.php`):

- **`app`** — general application events, level `LOG_LEVEL` (default `info`
  in production). To stdout.
- **`errors`** — errors and above only, level hardcoded (never env-driven,
  so it can't go silent because `LOG_LEVEL` was tightened elsewhere). To
  stderr.
- **`jobs`** — queue lifecycle (`job.processed`/`job.failed`, from
  `App\Listeners\LogJobLifecycle`) and publish outcomes
  (`publish.attempt`, from `PublishService::publishLockedChannel()`). To
  stdout.
- **`viewer`** — dated plain-text files `storage/logs/viewer-YYYY-MM-DD.log`
  in Laravel's line format, level `LOG_LEVEL`, `LOG_DAILY_DAYS` (default 14)
  files kept. The only channel that writes a file.

Production `.env` sets `LOG_STACK=app,errors,viewer`. Local dev stays
`LOG_STACK=single` (a text file under `storage/logs/`) — nobody wants JSON
in a terminal.

Every API request carries a `request_id`, added by
`App\Http\Middleware\AttachLogContext` (honours a client-supplied
`X-Request-Id`, otherwise mints a ULID) and echoed back as the
`X-Request-Id` response header. `App\Http\Middleware\ResolveOrganization`
adds `organization_id`/`user_id` once the tenant is known. All three ride
Laravel's `Context` facade, which Laravel dehydrates into any job dispatched
during the request automatically. nginx's own JSON access log
(`docker/nginx/default.conf`) reads the same id back out via
`fastcgi_param HTTP_X_REQUEST_ID` — one id joins the access line to the
application lines for a given request.

A Monolog processor (`App\Logging\RedactSensitiveContext`) redacts any
context/extra value whose key matches
`token|secret|password|authorization|cookie|api[_-]?key`, at any nesting
depth, as defence in depth. It is not the primary control: never log post
content, media URLs, signature text, tokens, request/response bodies, or
email addresses (log `user_id`) in the first place.

### Finding everything about one post

stdout is JSON, so filter it with `jq`:

```bash
docker compose -f docker/compose.yaml logs --no-log-prefix php horizon \
  | jq -c 'select(.context.post_group_id == "<ULID>")'
```

`post_group_id` is on every `publish.attempt` line; `request_id` joins a web
request to the jobs it dispatched. The same search works in Log Viewer's text
box (below), which is the easier route for anyone without shell access.

### Pulse

`/pulse` (`laravel/pulse`) shows what the logs do not: slow requests, slow
SQL (with file and line), slow jobs, slow outgoing API calls, exceptions,
queue throughput, the most active users, and server CPU/memory/disk. It is
the answer to "what is slow", where Horizon answers "what is queued or
failed". Access is the same `viewMonitoring` gate, bound to `viewPulse` in
`AppServiceProvider`. Pulse's own default is "allowed when `APP_ENV` is
`local`", and this stack is always `local`, so without that binding every
guest could open it.

Metrics live in the application Postgres (`pulse_values`, `pulse_entries`,
`pulse_aggregates`) and are trimmed to seven days by Pulse itself during
ingest; there is no scheduled prune. The `storage` ingest driver writes at the
end of each request. If Pulse ever shows up as the slow thing on its own
slow-requests card, move to `PULSE_INGEST_DRIVER=redis` and run
`php artisan pulse:work`.

Thresholds in `config/pulse.php` are set for this product, not the package
defaults: requests 500 ms, queries 300 ms, jobs 5 s (a publish legitimately
spends seconds in provider calls), outgoing requests 2 s. User and cache
cards are sampled (20% / 10%). The dashboards themselves, Livewire polling
and `/up` are excluded from the request cards.

**Outgoing URLs are collapsed to their host, and that is a security control.**
Some providers carry the channel's bot token in the URL path, Pulse stores and
displays the URL of every slow outgoing request, and the grouping is what
keeps the token out of `pulse_aggregates`. Do not remove it
(`PulseConfigTest` pins it).

### Log Viewer

`/log-viewer` (`opcodesio/log-viewer`) browses the `viewer` files: file list,
level filter, text search, jump by date. Access is the platform-wide
`viewMonitoring` gate (`users.is_superadmin`), wired in `AppServiceProvider`
through `LogViewer::auth()`. Two things in that wiring are deliberate:

- Without an auth callback or a `viewLogViewer` gate the package lets
  **everyone** in outside production and refuses everyone in production.
  Never remove the callback.
- File and folder deletion is switched off (`deleteLogFile` /
  `deleteLogFolder` are `false`). Rotation is the only thing that removes a
  file.

The `viewer` channel is a `monolog` channel on purpose. Laravel's `daily`
driver silently ignores a `processors` key, so a `daily` channel carrying
`RedactSensitiveContext` writes tokens to disk in clear with no error
(`ViewerLogChannelTest` pins this). The file is created `0666` because
php-fpm (`www-data`) and `horizon`/`scheduler` (`root`) all append to it and
the first writer creates it.

`php`, `horizon` and `scheduler` share one named volume,
`easypost-logs`, at `storage/logs`. Without it the jobs' lines stay inside
the `horizon` container and Log Viewer never sees them. On first use the
volume copies the owner and mode of `/var/www/html/storage/logs` from the
image (`docker/php/Dockerfile` creates it for `www-data`). **On a host that
already ran the stack, `make up` after pulling is enough; the volume starts
empty, so earlier `storage/logs/*.log` files are not carried into it.**

Every service's container logging driver is capped
(`logging: {driver: json-file, options: {max-size: "10m", max-file: "3"}}`
in `docker/compose.yaml`) — once all application logs go to stdout, the
Docker daemon's unbounded default is a disk-fill risk on the app host that
didn't exist while logs went to a Laravel-rotated file.

## Deploying a change

1. Pull/build the new code.
2. Run `composer install --no-dev -o` (production) or `make setup` (dev).
3. **Diff `.env` against `.env.example` and add every key that is missing.**
   A new key in `.env.example` changes nothing on its own — Laravel falls back
   to the default compiled into `config/`, which is exactly what the
   environment was already doing wrong. **For this release specifically, set
   `REDIS_QUEUE_RETRY_AFTER=300`**: without it the environment keeps
   `retry_after = 90`, equal to `supervisor-publish`'s timeout, and a slow
   publish is redelivered to a second worker while the first is still sending
   — segments already on the network get sent twice (troubleshooting entry 3
   below has the full interleaving). Then:

   ```bash
   php artisan config:clear     # or config:cache, if you cache config
   php artisan horizon:terminate # workers re-read config only on restart
   ```

4. Run migrations: `php artisan migrate --force` (or `make migrate`).
5. Restart `php`, `horizon`, and `scheduler` so they pick up new code (PHP-FPM
   workers and long-running Artisan processes do not hot-reload).
6. **Restart nginx too** — see the first troubleshooting entry below; skipping
   this step is the single most common cause of a "deploy that looks broken."
7. Rebuild frontend assets (`npm run build`) if `resources/js` changed, and
   make sure they're served from wherever `public/build` is expected in
   production (the bind-mount setup here is dev-only).
8. **For the durable-scheduling release specifically**, run
   `php artisan posts:backfill-durable-scheduling` — but only now, **after**
   step 5. That command converts every leftover `QUEUE` post group (from
   before this release) to `SCHEDULED`. It is deliberately NOT part of the
   migration in step 4: migrations run before `php`/`horizon`/`scheduler`
   restart, so a migration that rewrote `QUEUE` rows to `SCHEDULED` there
   would leave a window where the database already meant the new thing while
   every still-running process had old code loaded, expecting `QUEUE` to mean
   "a delayed job exists" — an old delayed job firing in that window would
   find its row `SCHEDULED`, silently no-op, and (in the old code) nothing
   would ever pick it back up. Running this command manually, after the
   restart, means the new dispatcher (`posts:dispatch-due`, scheduled every
   minute) is already live by the time any row changes meaning. Safe to
   re-run if you're ever unsure whether it already ran — a second run
   converts zero rows.

## Troubleshooting

### 1. 502 from nginx after restarting the `php` container

nginx resolves the `php` hostname to a container IP once, when its worker
process starts (or reloads), via `fastcgi_pass php:9000` in
`docker/nginx/default.conf`. That resolution is **not** re-checked per
request. Docker Compose's embedded DNS can hand a recreated `php` container
a *different* IP than it had before (this is a property of Docker's IPAM
allocator, not something this app controls) — when that happens, nginx keeps
sending FastCGI requests to the old, now-dead IP and every request 502s,
even though `php` itself is healthy and reachable directly.

This bites specifically after `docker compose restart php`,
`docker compose up -d --force-recreate php`, or any deploy step that
recreates (rather than merely reloads code inside) the `php` container.

**Fix:** whenever `php` is restarted or recreated, restart `nginx`
immediately after:

```bash
docker compose -f docker/compose.yaml restart php
docker compose -f docker/compose.yaml restart nginx
```

If this is scripted (a deploy pipeline, `make` target), always pair the two
restarts — never restart `php` alone and assume nginx will notice.

### 2. Rotating `APP_KEY` breaks every connected channel

`Integration::$token` and `Integration::$refresh_token` are stored with
Eloquent's `encrypted` cast, which is keyed off `APP_KEY`. If `APP_KEY`
changes, every already-stored provider token becomes permanently
undecryptable — Laravel raises a `DecryptException` the next time the
application tries to read it (the next publish attempt, the next token
refresh sweep, the next time a user views their connected channels).

**There is no re-encryption tooling in this project.** A key rotation is not
a "rotate and re-encrypt" operation here the way it might be for, say,
session cookies — it is effectively "every organization must reconnect every
channel from scratch." There is no way to recover the plaintext of an
already-encrypted token after the key that encrypted it is gone.

**Treat `APP_KEY` as permanent** for the lifetime of a deployment that has
any real connected channels:

- Generate it once (`make setup` / `php artisan key:generate` does this only
  if `.env` doesn't already have one — see the Makefile's `setup` target
  comment).
- Back it up outside the deployment itself (secrets manager, sealed copy) —
  losing it has the exact same effect as rotating it.
- Never run `php artisan key:generate` against an environment that already
  has data. If a key rotation is ever unavoidable (suspected key
  compromise), plan it as a full "every organization reconnects every
  channel" event, not a routine maintenance step.

### 3. Queue timeouts: `REDIS_QUEUE_RETRY_AFTER` must exceed every worker timeout

`config/queue.php`'s `redis.retry_after` is how long the queue waits before
deciding a reserved job was abandoned and letting another worker pop the same
payload. `config/horizon.php`'s per-supervisor `timeout` is how long a worker
is allowed to spend on one job before it is SIGALRM-killed. Laravel's rule is
that **`retry_after` must be strictly greater than the longest possible job
runtime**, and it was not: both were 90 seconds, and `.env`/`.env.example`
never set `REDIS_QUEUE_RETRY_AFTER` at all, so the 90-second env default
applied in production.

What that produces for `PublishPostJob` — the reason this is a deployment
concern and not a tuning preference:

1. Worker W1 pops the job and enters `PublishService::publishChannel()`'s
   transaction. It sends segments 1 and 2 of a 3-segment thread — those two
   messages are now **live on the network** — with the transaction still open.
2. At t = 90 s the reservation expires and worker W2 pops the identical
   payload; it blocks on W1's row locks.
3. Also at t = 90 s, W1 is killed. Postgres rolls its transaction back, so
   segments 1 and 2 have no `release_id` and are back at `QUEUE`.
4. W2's lock wait resolves, it sees three `QUEUE`/null rows, and sends the
   whole chain again. **Segments 1 and 2 are published twice.**

Two settings keep that shut, and both must hold on every environment:

- `REDIS_QUEUE_RETRY_AFTER=300` (see `.env.example`) against
  `supervisor-publish`'s `timeout: 90`. If you raise a supervisor timeout,
  raise this first and by more.
- Every provider HTTP call carries an explicit `->timeout()` /
  `->connectTimeout()` (see `App\Social\Providers\BoundsHttpTimeouts`), so a
  hung remote host cannot stretch a single publish past the worker timeout
  while inheriting Laravel's 30-second default per call.

Note what this does **not** fix, by design: if a worker is killed (deploy,
OOM, the timeout above) *while* a provider call is in flight, that channel's
transaction rolls back and the segments it had already sent are re-sent on the
next attempt. The per-channel commit boundary bounds that to the one channel
in flight; nothing can bound it further without a provider-side idempotency
key, which none of the five networks here offers. Prefer draining Horizon
(`php artisan horizon:terminate` and waiting) over hard-killing it during a
deploy.

## Plans (no billing)

This project ships with no Stripe, no Cashier, and no billing integration of
any kind — deliberately, see `config/plans.php` and
`App\Console\Commands\SetOrganizationPlanCommand`. An organization's plan
(`free`, `standard`, or `pro`, each with its own channel/posts-per-month/
media-storage limits — see `config/plans.php`) is set **only** by an operator
running:

```bash
php artisan org:plan {organization-id} {free|standard|pro}
```

There is no self-serve upgrade flow, no checkout, no webhook that could ever
change a plan. Every organization starts on `free` (see the `organizations`
migration's default) and stays there until this command is run against it.
`PlanLimitService::withLimit()` enforces the limits transactionally at the
one point each resource (channel, post, media upload) is created; nothing
elsewhere in the codebase re-derives or caches a usage count.

## Uploaded media is public-by-URL

Everything in the `media` disk is stored with public visibility, so
`https://{bucket-host}/{path}` serves the file to anyone who has the URL,
with no authentication and no expiry.

The URL is not guessable — the path is built from ULIDs — so nothing here is
enumerable, and every route that *hands out* a URL is tenant-scoped as
usual. What is true is that a URL, once it has left the product, keeps
working: forwarded in chat, present in a browser's history, or held by
someone who has since been removed from the organization.

This is a deliberate trade-off, reviewed and kept at MVP close-out. Signed,
expiring URLs are technically available (publishing does not depend on
public URLs — `PublishService` hands providers the file's *bytes*, via
`MediaFileService::copy()`, never a link), but every consumer that displays
media — the SPA and the public share page — would move to links that expire,
so an open tab or an open share page would eventually show broken images.
The chosen answer for an MVP with no production data is the simpler
lifetime, stated plainly here, rather than an expiry window picked without
evidence about how long those pages stay open.

Revisit this before the product carries anything genuinely confidential.
The change is: `visibility` to `private` on the `media` disk
(`config/filesystems.php`) and in `MediaUploadService`, `Media::url()`/
`thumbnailUrl()` to `temporaryUrl()`, and the disk in tests faked with
`['serve' => true]` so the local driver can mint temporary URLs at all.
