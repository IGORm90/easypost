# EasyPost

A small social-scheduler MVP: connect Telegram, Mastodon, Bluesky, Discord and
Slack channels, compose a post (with per-channel variations and threads),
schedule it, and watch it publish. Multi-tenant (organizations), Laravel 13
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

### Database

| Variable | Purpose |
|---|---|
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Postgres connection. The Docker Compose setup also auto-provisions a second `app_test` database for the test suite (see `docker/postgres/init-test-db.sql` and `make ensure-test-db`). |

### Sessions, cache, queue

| Variable | Purpose |
|---|---|
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_DOMAIN` | The SPA authenticates via Sanctum's stateful-SPA cookie session, not API tokens. |
| `SANCTUM_STATEFUL_DOMAINS` | Must include every host the SPA is served from (`localhost:8080` via nginx, `localhost:5173` via the Vite dev server) or auth requests won't be recognised as first-party. |
| `CACHE_STORE` | Redis in dev/prod; Mastodon's per-instance dynamically-registered OAuth app id/secret is cached here (see `MastodonProvider`). |
| `QUEUE_CONNECTION` | Redis, consumed by Horizon. `PublishPostJob` (the job that actually calls out to a provider) is dispatched here, delayed until `publish_date`. |

### Mail

| Variable | Purpose |
|---|---|
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | SMTP settings. In dev these point at Mailpit (`mailpit:1025`), which never leaves the Docker network — nothing is actually delivered to a real inbox locally. Used for verification emails, invites, and "channel needs reconnecting" notices. |

### Media storage (S3-compatible)

| Variable | Purpose |
|---|---|
| `FILESYSTEM_DISK` | `s3` — media always goes through the `media` disk, never local disk (see `MediaUploadService`). |
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
unrelated to the five publishing providers below.

### Publishing channels (the five providers)

| Variable | Purpose |
|---|---|
| `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET` | OAuth2 app credentials for connecting a Discord channel. |
| `SLACK_INTEGRATION_CLIENT_ID`, `SLACK_INTEGRATION_CLIENT_SECRET` | OAuth2 app credentials for connecting a Slack workspace/channel. Distinct from `SLACK_BOT_USER_OAUTH_TOKEN`/`SLACK_BOT_USER_DEFAULT_CHANNEL` below, which are this app's *own* ops alert channel, not a user-facing feature. |
| `OAUTH_SPA_REDIRECT_URL` | The SPA's own base URL, used as the default login-callback destination. |
| `INTEGRATIONS_REDIRECT_URL` | The SPA page (`/integrations/callback` by default) that receives Discord/Slack/Mastodon's OAuth redirect and forwards `code`/`state` to the backend. |

Telegram and Bluesky need no app-level credentials at all — see "Obtaining
provider credentials" below.

### Ops (optional)

| Variable | Purpose |
|---|---|
| `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL` | If set, this app posts its own operational alerts (e.g. failed-publish digests) to a Slack channel via Laravel's `slack` log/notification channel. Unrelated to end users connecting Slack as a publishing channel. |
| `POSTMARK_API_KEY`, `RESEND_API_KEY` | Alternative transactional-mail drivers, if not using SMTP/Mailpit. |

## Obtaining credentials for the five providers

Each is connected per-organization from **Settings → Channels** in the app
(`POST /api/integrations/{provider}/connect`, admin-only — a `USER`-role
member cannot connect or remove channels).

- **Telegram** — no app registration needed. Message [@BotFather](https://t.me/BotFather)
  on Telegram, run `/newbot`, and copy the bot token it gives you. Add the bot
  as an admin of the channel/group you want to post to, then get that chat's
  numeric id (e.g. via `@userinfobot` or the Bot API's `getUpdates`). Paste
  both into the connect form — this is a manual-token flow, not OAuth.
- **Mastodon** — no pre-registration needed either: enter the instance
  hostname you're on (e.g. `mastodon.social`) in the connect form. The backend
  dynamically registers an OAuth app with that instance on first use
  (`POST /api/v1/apps`, per-instance, cached) and redirects you through the
  instance's own consent screen.
- **Bluesky** — not OAuth. In the Bluesky app: Settings → Privacy and
  Security → App Passwords → create one. Enter your handle
  (e.g. `you.bsky.social`) and that app password in the connect form —
  **never your main account password**.
- **Discord** — requires an app registered at the
  [Discord Developer Portal](https://discord.com/developers/applications).
  Create an app, add an OAuth2 redirect URI matching
  `INTEGRATIONS_REDIRECT_URL`, and put its client id/secret in
  `DISCORD_CLIENT_ID`/`DISCORD_CLIENT_SECRET`. Connecting a channel walks the
  user through Discord's `webhook.incoming` consent screen, where they pick
  the channel to post into; the token stored is the resulting webhook itself.
- **Slack** — requires an app registered at
  [api.slack.com/apps](https://api.slack.com/apps). Add an OAuth redirect URL
  matching `INTEGRATIONS_REDIRECT_URL`, request the scopes needed to post
  messages, and put its client id/secret in
  `SLACK_INTEGRATION_CLIENT_ID`/`SLACK_INTEGRATION_CLIENT_SECRET`.

## Running the tests

Backend (inside the `php` container):

```bash
make test                          # ./vendor/bin/pest style run via artisan, ensures app_test exists first
./vendor/bin/pest                  # same, run directly
./vendor/bin/phpstan analyse       # static analysis
./vendor/bin/pint --test           # code style check (add --test to check, drop it to fix)
composer audit                     # dependency vulnerability check
```

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
