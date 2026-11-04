# AGENTS.md — EasyPost

## Overview

EasyPost is a multi-tenant social-post scheduler. You connect Telegram and Bluesky channels,
compose a post (with per-channel variations and threads), schedule it, and watch it publish.

**Laravel 13 REST API + a separate Vue 3 SPA.** There is no Inertia — the SPA talks to the API over
axios with Sanctum cookie auth. Everything is scoped to an `Organization`.

The repo is a Laravel/Vue rewrite of Postiz; the original inventory and mapping live in
`LARAVEL_REWRITE_PLAN.md`.

### Detailed docs (read the relevant one before working in that area)

- **`README.md`** — env-var tables, per-provider credential setup, quick start, known issues.
- **`docs/ACCEPTANCE.md`** — the manual acceptance checklist, plus two sections that matter a lot:
  what is *backend-built with no UI yet* (do not count those as features in an acceptance pass) and
  *what the automated gates do not cover*.
- **`docs/DEPLOYMENT.md`** — the nine-service topology, the "APP_KEY is permanent" warning, queue
  timeout rules.
- **`color.md`** — the design-token palette spec (Russian). Mechanically enforced; see Hard rules.

---

## Stack

PHP 8.3 / Laravel 13 · PostgreSQL 17 · Redis 7 (session + cache + queue) with Horizon ·
MinIO (S3-compatible) for media · Mailpit for local mail · Sanctum (auth) + Socialite
(GitHub / Google / generic OIDC login) · Vue 3.5 + Vite 8 + Pinia + vue-router + vue-i18n +
Tailwind 3 · Pest 4 on PHPUnit 12 · Vitest 4 + happy-dom · Pint (`laravel` preset) ·
Larastan/PHPStan **level 6**.

---

## Quick start

```bash
cp .env.example .env
make up        # docker compose up -d --build
make setup     # composer install, guarded key:generate, app_test db, migrate, start horizon+scheduler
make seed      # DemoSeeder: demo workspace, channels, media, posts in every state
```

Demo login after seeding: `demo@easypost.test` / `demo12345`.

**Ports:** app `http://localhost:8080` · Vite `5173` · Mailpit UI `8025` · MinIO console `9001`.

Other Make targets: `make sh` (shell in the `php` container) · `make migrate` · `make test` ·
`make ensure-test-db` · `make down`. Everything runs through
`docker compose -f docker/compose.yaml`.

> There is no `composer setup`. It was deleted on purpose because its unguarded `key:generate`
> made already-encrypted integration tokens undecryptable. `make setup` is the only setup path —
> it only generates a key when `.env` has none.

---

## Architecture

Classic Laravel layered MVC with a service layer. **Not DDD, not modular** — do not introduce
bounded contexts, ports/adapters or a `src/` tree.

| Layer | Location |
| --- | --- |
| HTTP | `app/Http/Controllers/Api/`, `app/Http/Requests/`, `app/Http/Resources/`, `app/Http/Middleware/` |
| Business logic | `app/Services/{Account,Admin,Auth,Billing,Integrations,Mail,Media,Organizations,Posts}/` |
| Publishing providers | `app/Social/{Contracts,Dto,Exceptions,Providers}/` + `ProviderRegistry` |
| Persistence | `app/Models/` (+ `Models/Concerns/BelongsToOrganization`), `database/{migrations,factories,seeders}` |
| Async | `app/Jobs/`, `routes/console.php`, Horizon |
| SPA | `resources/js/{pages,components,stores,api,composables,layouts,utils,i18n}` |

Routes: `routes/api.php` (Sanctum-stateful, the bulk of the app), `routes/web.php` (OAuth
redirect/callback deliberately live here so a session exists), `routes/console.php` (token refresh
hourly, `ScanMissedPostsJob` every 10 min, digest email hourly).

### Domain vocabulary

- `Organization` — the tenant. `OrganizationUser` is the role pivot; `Invite` brings people in.
- `Integration` — a *connected publishing channel* (a Telegram bot, a Bluesky account). Its
  `token`/`refresh_token` use the `encrypted` cast. `IntegrationTimeSlot` holds its posting slots.
- `SocialAccount` — a *login identity* (GitHub/Google/OIDC). Not a publishing channel. Don't confuse
  the two.
- `PostGroup` — one scheduled publication across channels. It fans out to `Post` rows, one per
  channel segment, threaded via `parent_post_id` + `order`.
- Supporting: `Media`, `Tag`, `Set` (channel preset), `Signature`, `Comment`.

### Tenancy

`X-Organization` header → `ResolveOrganization` middleware → `App\Support\OrganizationContext` →
the `organization` global scope on tenant-owned models. Middleware aliases in use: `auth:sanctum`,
`organization`, `role:ADMIN` (hierarchical via `OrganizationRole::atLeast()`), `superadmin`,
`verified`, `deny_while_impersonating`, `plan:{limit}`.

Billing is intentionally not implemented — no Stripe, no Cashier. Plans are assigned by the operator
command `php artisan org:plan`.

---

## Hard rules

Each of these is enforced by a test that will fail the build. They exist because someone already got
bitten.

**Provider isolation.** No provider-specific string or branch outside `app/Social/Providers`.
The guard greps `app/**/*.php` for `telegram|mastodon|bluesky|discord|slack` inside any quoted
string — `mastodon`, `discord` and `slack` stay in that list on purpose after those providers were
removed, so a stray reference cannot come back unnoticed — `tests/Unit/Architecture/ProviderIsolationTest.php`. The SPA mirrors this: only
`resources/js/components/previews/registry.ts` may map a provider id to behaviour —
`resources/js/__tests__/providerIsolation.spec.ts`.

**HTTP timeouts in providers.** Never call the `Http` facade under `app/Social` outside the
`BoundsHttpTimeouts` trait, and every provider must send its calls through `ProviderCall` (directly, or
via a trait that does, like `UsesMetaGraph`), which is the one place a redirect is refused —
`ProviderHttpTimeoutTest.php`. A bare `Http::post()` inherits Laravel's 30s default while the
publish worker's own timeout is 90s.

**Global scopes.** Never `withoutGlobalScopes()`. Remove the tenant scope by name only:
`withoutGlobalScope('organization')` — `GlobalScopeRemovalTest.php`.

**Plan limits.** `PlanLimitService` may only be touched from the allow-listed callers in
`PlanLimitControllerIsolationTest.php`. `PlanController` *reads* limits; it never enforces them
(no `withLimit(` / `->check(` there). Enforcement belongs in `EnforcePlanLimit` middleware and the
services.

**Colour.** No hex literals, no `rgb()`/`hsl()`, no CSS named colours, no default Tailwind palette
classes (including `from-`/`to-`/`via-`/`fill-`/`stroke-`/`divide-` variants) anywhere in
`.vue`, `.ts`, `.blade.php` or `.css`. Use the design tokens only — Tailwind maps every colour to a
`var(--…)` defined in `resources/css/app.css` under `:root` / `.dark`. Spec in `color.md`, enforced
by `resources/js/__tests__/palette.spec.ts`. `darkMode: 'class'`; the dark theme is a deliberate
palette, not an inversion.

**Locale parity.** `lang/en` ↔ `lang/ru` and `resources/js/i18n/en.json` ↔ `ru.json` must have
identical key trees — `LocaleParityTest.php`. Add a key to one, add it to the other.

**Accessibility.** Every `<Input>` call site passes `label`, `aria-label` or `aria-labelledby` —
`resources/js/__tests__/a11y.spec.ts`. A placeholder is not a label.

Rules that are documented rather than grepped, but no less binding:

- **`PostState::PUBLISHED` is terminal.** The single worst failure this product can produce is
  publishing a customer's post twice. Treat any transition out of `PUBLISHED` as a bug.
- **Never run `php artisan key:generate` on an install that already has connected channels.**
  It makes every encrypted `Integration` token undecryptable, permanently.
- `REDIS_QUEUE_RETRY_AFTER` must stay above the longest Horizon supervisor timeout (300 vs 90) or a
  slow publish gets redelivered to a second worker.
- Route order: literal segments (`find-slot`, `errors`, `stop`) must be registered before
  `{group}` / `{user}` wildcards.
- `FILESYSTEM_DISK` has no effect on media — `MediaUploadService` always writes the `media` s3 disk.
- Never restart the `php` container alone and assume nginx notices (`docs/DEPLOYMENT.md`).

---

## Testing

**Backend — Pest 4.** `tests/Feature/{Admin,Auth,Billing,EndToEnd,Integrations,Mail,Media,Organizations,Posts,Publishing}`
run with `RefreshDatabase`; `tests/Unit/{Architecture,Billing,Posts,Services,Social,Support}` run
without it (see `tests/Pest.php`). Shared helpers and fixtures live in `tests/Pest.php`,
`tests/Support/`, `tests/Fixtures/`.

**Frontend — Vitest 4.** Specs are colocated in `__tests__/` next to the code they cover
(`resources/js/**/*.spec.ts`), happy-dom environment, `TZ=America/Chicago` pinned on purpose so
timezone assertions can't pass for the wrong reason.

```bash
make test                                  # ensure-test-db, then php artisan test in the container
./vendor/bin/pest                          # full backend suite
./vendor/bin/pest --filter=PlanLimitServiceTest
npx vitest run                             # full frontend suite
```

Test conventions:

- Use the `actingAsMember($org, $role)` helper, and `Organization::addMember()` — **never**
  `members()->attach()`, which leaves `organization_user.id` null.
- In the `pcntl_fork` concurrency racer, each child gets a brand-new Eloquent connection name.
  Never `DB::purge()` there — it kills the parent's shared PDO socket. Use `raceAwaitBarrier()`,
  not `usleep()`.
- `phpunit.xml` forces `DB_DATABASE=app_test`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`,
  `SESSION_DRIVER=array` as both `<env force>` and `<server>` — the `<server>` entries are the ones
  that win, because Compose's `env_file` exports real env vars.

**A green suite is not an acceptance pass.** `docs/ACCEPTANCE.md` records exactly what the gates do
not prove: FullCalendar is stubbed in `CalendarGrid.spec` (drag-and-drop *decision logic* is
covered, the library invoking the callbacks is not), `MvpFlowTest` runs on the `fake` provider with
`Queue::fake()` so it is not end-to-end, and CI never provisions MinIO — media tests use
`Storage::fake('media')`, so the real object-storage write path is untested.

---

## Gates

`.github/workflows/ci.yml` runs on pushes to `main` and on every PR. This order is also the
pre-commit checklist — all of it must pass before a commit lands:

```bash
./vendor/bin/pint --test                              # style
php -d memory_limit=1G ./vendor/bin/phpstan analyse   # level 6 over app, database, tests
./vendor/bin/pest                                     # backend suite
php artisan migrate --force && php artisan migrate:rollback --step=100 --force && php artisan migrate --force
npm ci && npm run typecheck && npx vitest run && npm run build
```

The `-d memory_limit=1G` is not optional — PHPStan OOMs on PHP's 128M default and dies with a fatal
rather than a finding.

The migration rollback step exists because `RefreshDatabase` is `migrate:fresh` and never runs a
`down()`. An unexecuted `down()` is not a rollback path.

`composer audit` is deliberately excluded from CI: it fails on whatever advisory happens to be
published that morning and turns a green build into a coin toss.

Auto-fix style with `./vendor/bin/pint` (or `./vendor/bin/pint --dirty`).

---

## Conventions

### Code style

- Pint `laravel` preset (`pint.json`). PSR-4 `App\` → `app/`, one class per file, filename == class.
- **Do not add `declare(strict_types=1)`.** It appears in exactly one file out of ~190; it is not the
  house style here, and sprinkling it in creates diff noise.
- Constructor property promotion with `private readonly` for injected dependencies.
- String-backed enums with UPPERCASE cases (`PostState::DRAFT|QUEUE|PUBLISHED|ERROR`,
  `OrganizationRole::SUPERADMIN|ADMIN|USER`); `Plan` is the exception (`free|standard|pro`).
- **Comments in English.** They are expected to be substantial — explain *why*, name the failure the
  code prevents. That is the prevailing style in this repo; match it rather than stripping it.
- `.editorconfig`: LF, UTF-8, 4-space indent (2 for yml, 4 for compose files).

### Naming

- Suffixes: `*Controller`, `*Request`, `*Resource`, `*Service`, `*Policy`, `*Job`, `*Provider`,
  `*Command`, `*Test`, `*Factory`.
- Form requests take a verb prefix: `StorePostRequest`, `UpdateTagRequest`.
- Traits are verb phrases describing behaviour: `BoundsHttpTimeouts`, `MapsHttpErrors`,
  `ReadsMediaFiles`, `BelongsToOrganization`.
- Frontend: PascalCase SFCs, `pages/<Area>/Index.vue`, camelCase stores/composables/utils
  (`useTheme.ts`, `zonedDateTime.ts`).

### Git

Conventional Commits, lowercase, always scoped, **no ticket keys**:

```
fix(tenancy): close the minor cross-tenant gaps, and make three listeners three tests
feat(ui): build the two screens the backend was already serving
chore(gates): run down() in CI, and write down what the suite does not prove
```

Scopes in use: `tenancy, ui, a11y, i18n, api, auth, calendar, integrations, publishing, providers,
mastodon, ssrf, queue, seeder, review, e2e, posts, makefile, readme, gates, frontend`.

Bodies are long and explanatory — root cause, why this fix, and a closing `Verified: …` line stating
what actually ran. All work is done on `main` — no feature branches.

**Always ask before committing, and before pushing.** Never auto-commit.

> Known wrinkle: the local branch is `master`, but CI triggers on `main`. Don't invent a rule around
> this — just be aware pushes to `master` don't run CI.

---

## Before claiming something works

The user should not be the one to find a broken endpoint.

1. Run the actual gates above — don't infer from "the code looks right".
2. Hit new or changed endpoints for real (curl / the SPA), including the error paths.
3. Check the JSON shape the resource actually returns, not the one you intended.
4. For anything queued, confirm the job ran — `Queue::fake()` in a test proves dispatch, not publish.
5. Never claim media works from a test alone: CI has no MinIO and the suite fakes the disk.
6. State what you verified and what you did not.

---

## Code review checklist

- [ ] Pint, PHPStan (with `-d memory_limit=1G`), Pest, typecheck, Vitest, build — all clean
- [ ] No provider name in a quoted string outside `app/Social/Providers` / `previews/registry.ts`
- [ ] No `Http::` under `app/Social` outside `BoundsHttpTimeouts`; new providers send their calls through `ProviderCall`
- [ ] No `withoutGlobalScopes()`; tenant scope removed by name only, and only where justified
- [ ] New queries on tenant-owned models respect the `organization` scope
- [ ] No colour literals — design tokens only
- [ ] Translation keys added to all four locale files (`lang/en`, `lang/ru`, `i18n/en.json`, `i18n/ru.json`)
- [ ] Every new input has a real label
- [ ] Literal route segments registered before wildcards
- [ ] New migrations have a working `down()` (CI rolls back 100 steps)
- [ ] Tests use `actingAsMember()` / `addMember()`, not `members()->attach()`
- [ ] No hardcoded credentials; secrets via env
- [ ] `docs/ACCEPTANCE.md` updated if the feature is backend-only or changes what the gates cover
