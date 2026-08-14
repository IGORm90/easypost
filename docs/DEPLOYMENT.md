# Deployment and operations

## Topology

Nine long-running services plus one one-shot init container
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
  refresh sweeps, digest emails).
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
