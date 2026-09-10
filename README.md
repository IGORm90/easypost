# EasyPost

A small social-scheduler MVP: connect Telegram and Bluesky channels, compose
a post (with per-channel variations and threads), schedule it, and watch it
publish. Multi-tenant (organizations), Laravel 13
API + Vue 3 SPA (Inertia-less, plain API + SPA), Postgres, Redis/Horizon for
queues, MinIO (S3-compatible) for media.

Billing is intentionally not part of this MVP — see
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for how a plan is assigned instead.

## Quick start

Three commands, from a fresh checkout:

```bash
cp .env.example .env
make up
make setup
```

- `make up` builds and starts every container (nginx, php, horizon, scheduler,
  node/vite, postgres, redis, minio, mailpit).
`make setup` is the only setup path. A second one used to exist as
`composer setup`, left over from the Laravel skeleton; it ran an unguarded
`php artisan key:generate`, which on an existing install silently makes
every stored provider token undecryptable — the exact operation
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) calls unrecoverable. It has been
deleted rather than guarded, so there is one way to do this and it is the
documented one.

- `make setup` installs PHP dependencies, generates `APP_KEY` (only if `.env`
  doesn't already have one — see the "APP_KEY is permanent" note in
  [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)), creates the `app_test` database,
  and runs migrations. It's idempotent — safe to re-run.

The app is then reachable at `http://localhost:8080` (via nginx) and the Vite
dev server at `http://localhost:5173`. Mailpit's UI (catches every outbound
email in dev, including invites and reconnect notices) is at
`http://localhost:8025`. MinIO's console is at `http://localhost:9001`
(credentials: `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` below).

To populate a walkable demo workspace (one organization, three connected
channels, five media files, ten posts across every lifecycle state):

```bash
make seed
```

This logs in as `demo@easypost.test` / `demo12345`. It's safe to re-run — it
rebuilds the same organization's content from scratch each time rather than
accumulating duplicates.

## Environment variables

All variables live in `.env` (copied from `.env.example`). Grouped by
purpose:

### Application

| Variable | Purpose |
|---|---|
| `APP_NAME` | Display name used in emails and the page title. |
| `APP_ENV` | `local` in dev, `production` in prod. |
| `APP_KEY` | Encrypts sessions, cookies, and — most importantly — every stored provider token (`Integration::$token`/`refresh_token` use Eloquent's `encrypted` cast). Generated once by `make setup`/`php artisan key:generate`. **Never rotate this in a deployment that already has connected channels** — see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). |
| `APP_DEBUG` | Must be `false` in production — a debug stack trace can leak more than intended even though tokens themselves are excluded from logs/exceptions by design. |
| `APP_URL` | Base URL used to build absolute links (e.g. in emails). |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | Must be `en` or `ru` — those are the only two translation sets shipped (`lang/en`, `lang/ru`); any other value falls through to missing-translation-key output. |

### Logging

| Variable | Purpose |
|---|---|
| `LOG_CHANNEL` | `stack` in every environment; which channels the stack fans out to is controlled by `LOG_STACK`. |
| `LOG_STACK` | `single` in dev (a human-readable text file under `storage/logs/`). **`app,errors` in production** — JSON to stdout/stderr instead, the shape a log shipper needs. See `config/logging.php` and [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)'s "Logging" section. |
| `LOG_LEVEL` | Minimum level for the `app` channel; `errors` always logs at `error` regardless of this value, by design. |

### Database

| Variable | Purpose |
|---|---|
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Postgres connection. The Docker Compose setup also auto-provisions a second `app_test` database for the test suite (see `docker/postgres/init-test-db.sql` and `make ensure-test-db`). |

### Sessions, cache, queue

| Variable | Purpose |
|---|---|
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_DOMAIN` | The SPA authenticates via Sanctum's stateful-SPA cookie session, not API tokens. |
| `SANCTUM_STATEFUL_DOMAINS` | Must include every host the SPA is served from (`localhost:8080` via nginx, `localhost:5173` via the Vite dev server) or auth requests won't be recognised as first-party. |
| `CACHE_STORE` | Redis in dev/prod. Also where a provider caches whatever it must not re-derive per request (a dynamically-registered OAuth app's id/secret, for instance). |
| `QUEUE_CONNECTION` | Redis, consumed by Horizon. `PublishPostJob` (the job that actually calls out to a provider) is dispatched here, delayed until `publish_date`. |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | The actual Redis connection details. Mandatory, not optional — `SESSION_DRIVER`, `CACHE_STORE`, and `QUEUE_CONNECTION` are all `redis` above, so without these all three silently point at `127.0.0.1` instead of the `redis` container. |

### Mail

| Variable | Purpose |
|---|---|
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | SMTP settings. In dev these point at Mailpit (`mailpit:1025`), which never leaves the Docker network — nothing is actually delivered to a real inbox locally. Used for verification emails, invites, and "channel needs reconnecting" notices. |

### Media storage (S3-compatible)

| Variable | Purpose |
|---|---|
| `FILESYSTEM_DISK` | Sets Laravel's *default* disk only (`config/filesystems.php`'s `default` key). Media uploads never use the default disk — `MediaUploadService` always writes to the `media` disk, which hardcodes `'driver' => 's3'` in `config/filesystems.php` regardless of this variable. Left at `s3` here for consistency, but changing it has no effect on where media goes. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Credentials for the `media` disk. In dev these are MinIO's root credentials (`minio` / `minio123`). |
| `AWS_DEFAULT_REGION`, `AWS_BUCKET` | Bucket the media disk writes to. |
| `AWS_ENDPOINT` | Where the **server** (php/horizon/scheduler containers) reaches storage — `http://minio:9000` only resolves inside the Compose network. |
| `AWS_URL` | Where a **browser** reaches storage — must be a publicly reachable host:port. In dev this is MinIO's published port, `http://localhost:9000/media`. In production this diverges from `AWS_ENDPOINT` for the same reason: server-to-storage and browser-to-storage are different networks. |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` — required for MinIO; a real AWS S3 bucket would typically use virtual-hosted-style instead. |

### Social login (organization sign-in, not publishing channels)

`GITHUB_CLIENT_ID`/`GITHUB_CLIENT_SECRET`/`GITHUB_REDIRECT_URI`,
`GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`/`GOOGLE_REDIRECT_URI`, and
`OIDC_CLIENT_ID`/`OIDC_CLIENT_SECRET`/`OIDC_REDIRECT_URI`/`OIDC_BASE_URL` are
optional Socialite providers for signing **into** the app itself. They are
unrelated to the publishing providers below.

#### Google sign-in setup

1. In Google Cloud Console, configure the OAuth consent screen for the audience
   that may use this EasyPost deployment. While the app is in testing, add each
   permitted Google account as a test user.
2. Create an OAuth 2.0 **Web application** client and add this exact authorized
   redirect URI: `https://<public-app-host>/api/auth/oauth/google/callback`.
   It is the Laravel callback, not the Vite development-server URL or SPA
   `/login` route.
3. Store the issued values only in deployment secrets, then configure:

   ```dotenv
   GOOGLE_CLIENT_ID=...apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=...
   GOOGLE_REDIRECT_URI=https://<public-app-host>/api/auth/oauth/google/callback
   OAUTH_SPA_REDIRECT_URL=https://<public-app-host>
   ```

   `GOOGLE_REDIRECT_URI` must byte-for-byte match the URI registered in Google
   Cloud Console. After changing production environment values, refresh the
   Laravel configuration cache or redeploy the PHP and queue services.

### Publishing channels

| Variable | Purpose |
|---|---|
| `OAUTH_SPA_REDIRECT_URL` | The SPA's own base URL, used as the default login-callback destination. |
| `INTEGRATIONS_REDIRECT_URL` | The SPA page (`/integrations/callback` by default) that receives an OAuth provider's redirect and forwards `code`/`state` to the backend. |

The two shipped networks — Telegram and Bluesky — need no app-level
credentials at all; both are manual-token flows. See "Obtaining
credentials" below.

### Ops (optional)

| Variable | Purpose |
|---|---|
| `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL` | If set, this app posts its own operational alerts (e.g. failed-publish digests) to a Slack channel via Laravel's `slack` log/notification channel. This is the app talking to its operators — Slack is not a publishing channel here. |
| `POSTMARK_API_KEY`, `RESEND_API_KEY` | Alternative transactional-mail drivers, if not using SMTP/Mailpit. |

## Obtaining credentials

Each is connected per-organization from **Settings → Channels** in the app
(`POST /api/integrations/{provider}/auth-url` to start the flow, then
`POST /api/integrations/{provider}/callback` to complete it). Starting a
connection is admin-only — `auth-url` carries `role:ADMIN` middleware
(`routes/api.php`), so a `USER`-role member cannot start one. `callback`
itself carries no role check — it only verifies the OAuth `state` belongs
to the calling user and that they still belong to the organization it was
issued for (`OAuthStateService::consumeLocked()`) — but since only an
admin could ever obtain a valid `state` in the first place, a `USER`-role
member still cannot connect or remove a channel end to end.

- **Telegram** — no app registration needed. Message [@BotFather](https://t.me/BotFather)
  on Telegram, run `/newbot`, and copy the bot token it gives you. Add the bot
  as an admin of the channel/group you want to post to, then get that chat's
  numeric id (e.g. via `@userinfobot` or the Bot API's `getUpdates`). Paste
  both into the connect form — this is a manual-token flow, not OAuth.
- **Bluesky** — not OAuth. In the Bluesky app: Settings → Privacy and
  Security → App Passwords → create one. Enter your handle
  (e.g. `you.bsky.social`) and that app password in the connect form —
  **never your main account password**.
- **Reddit** — OAuth 2.0, one client pair registered per deployment
  (`INTEGRATIONS_REDIRECT_URL` is the redirect URI). Create a **web app**
  at [reddit.com/prefs/apps](https://www.reddit.com/prefs/apps). Set
  `REDDIT_CLIENT_ID`/`REDDIT_CLIENT_SECRET`, and also `REDDIT_USER_AGENT`
  — Reddit refuses generic or absent user agents outright, so use
  something like `easypost/1.0 by /u/your-account`. The connect flow asks
  for `duration=permanent`; without it Reddit issues no refresh token and
  the channel dies within the hour. Unlike Telegram and Bluesky, clicking
  "Connect" on Reddit's card opens Reddit's own login in a new tab
  directly — there is no credential form, since nothing needs to be typed
  before the redirect. A connected channel can post to any subreddit it
  moderates or is a member of; the subreddit and title are chosen per
  post, not at connect time.
## Obtaining credentials for the seven remaining Block 5 networks

All seven are OAuth 2.0 client pairs registered per deployment, and all
seven use the same redirect URI — `INTEGRATIONS_REDIRECT_URL` — because
the SPA, not the backend, receives the provider's redirect and posts the
code back. Leaving a pair empty only means that channel cannot be
connected here; nothing else is affected.

**Apply for app review on day one.** Publishing on behalf of a user
requires an approved app on Meta (Facebook, Instagram, Threads), X and
LinkedIn, and approval takes weeks of calendar time. Development against
your own test accounts does not wait for it; acceptance does.

- **X** — register at the [X Developer Portal](https://developer.x.com).
  Enable OAuth 2.0 with PKCE, request `tweet.read`, `tweet.write`,
  `users.read` and `offline.access` (the last one is what makes a refresh
  token exist at all — without it the channel breaks two hours after
  connecting), and set `TWITTER_CLIENT_ID`/`TWITTER_CLIENT_SECRET`.
  **Check the current API tier before relying on this channel**: writing
  is not available on the free tier, so a deployment without a paid plan
  can connect the channel and still fail to publish.
- **LinkedIn (profile)** — register at the
  [LinkedIn Developer Portal](https://www.linkedin.com/developers/apps).
  Request `openid`, `profile` and `w_member_social`; set
  `LINKEDIN_CLIENT_ID`/`LINKEDIN_CLIENT_SECRET`.
- **LinkedIn Page** — the same app, plus the `w_organization_social`
  scope and the Community Management API product. It is a separate
  channel in this app (different author URN, different permissions), and
  the organization to post as is chosen in the channel's settings after
  connecting.
- **Facebook Page** — register at
  [developers.facebook.com](https://developers.facebook.com/apps). Request
  `pages_show_list`, `pages_manage_posts` and `pages_read_engagement`; set
  `FACEBOOK_PAGE_CLIENT_ID`/`FACEBOOK_PAGE_CLIENT_SECRET`. The page to
  post as is chosen in the channel's settings after connecting; only the
  long-lived user token is stored, and the page token is fetched at
  publish time.
- **Instagram (Business)** — the same Meta app, requesting `pages_show_list`
  (to list which page's linked account to publish as), `instagram_basic` and
  `instagram_content_publish`; the Instagram account must be linked to a
  Facebook Page. Set
  `INSTAGRAM_CLIENT_ID`/`INSTAGRAM_CLIENT_SECRET`.
- **Instagram (Standalone)** — Instagram Login, for accounts with no
  Facebook Page. Set
  `INSTAGRAM_STANDALONE_CLIENT_ID`/`INSTAGRAM_STANDALONE_CLIENT_SECRET`.
- **Threads** — the Threads API product on a Meta app, scopes
  `threads_basic` and `threads_content_publish`; set
  `META_THREADS_CLIENT_ID`/`META_THREADS_CLIENT_SECRET`.

> **Instagram and Threads need media your storage serves publicly.** Both
> publish by handing Meta a URL it fetches for itself, never an upload, so
> `AWS_URL` must point at a publicly routable endpoint. The local default
> (`http://localhost:9000/media`, MinIO) is not reachable from Meta, which
> means these two channels cannot be accepted on a local stack — only on a
> deployment with real object storage.

## Running the tests

Backend (inside the `php` container):

```bash
make test                          # ./vendor/bin/pest style run via artisan, ensures app_test exists first
./vendor/bin/pest                  # same, run directly
php -d memory_limit=1G ./vendor/bin/phpstan analyse   # static analysis
./vendor/bin/pint --test           # code style check (add --test to check, drop it to fix)
composer audit                     # dependency vulnerability check
```

`-d memory_limit=1G` is not optional: PHPStan needs more than PHP's 128M
default to analyse this codebase and dies with an out-of-memory **fatal**
rather than a finding, which reads like a broken tool rather than a
too-small limit. CI passes the same flag.

`composer audit` is deliberately **not** wired into CI — it fails on
whatever advisory happens to be published that morning, including ones
with no fix available, which turns a green build into a coin toss. Run it
before a release and read the output.

Frontend (inside the `node` container, or locally with Node 22+):

```bash
npx vitest run                     # unit/component tests, including the palette guard specs
npm run build                      # production build — also the frontend type gate, see below
```

All of the above must pass before a commit lands. Run backend commands via
`docker compose -f docker/compose.yaml exec php <command>` and frontend ones
via `docker compose -f docker/compose.yaml exec node <command>` (or `make sh`
for an interactive shell).

## Known issues

- **`vue-tsc --noEmit` does not run** in this project — it fails immediately
  with `ERR_PACKAGE_PATH_NOT_EXPORTED` (a version mismatch between the
  pinned `typescript` and `vue-tsc` packages: `vue-tsc` looks for
  `typescript`'s internal `lib/tsc` entry point under an export path the
  installed `typescript` version no longer exposes). This is a tooling
  issue, not a code issue. Until the version pin is fixed upstream,
  `npm run build` (Vite/esbuild) is used as the practical frontend gate
  instead — it fails on syntax errors and unresolved imports, but note it
  is not a substitute for full type-checking: esbuild strips TypeScript
  types without verifying them, so it will not catch every class of type
  error `vue-tsc` would have.
