# MVP «Postiz на Laravel» — Implementation Plan (этапы 0–5 + лимиты тарифов)

> **For agentic workers:** REQUIRED SUB-SKILL: используйте `superpowers:subagent-driven-development` (рекомендуется) или `superpowers:executing-plans` для выполнения плана задача-за-задачей. Шаги размечены чекбоксами (`- [ ]`).

**Goal:** Собрать минимально жизнеспособный планировщик постов в соцсети: аккаунты и организации → подключение 5 каналов → календарь и редактор постов → медиа-библиотека → фоновая публикация с ретраями → централизованные лимиты тарифов (без приёма оплаты).

**Architecture:** Монолит Laravel 13 (PHP 8.3) отдаёт REST API под `/api` и одностраничное Vue 3 SPA с одного домена. Аутентификация — Sanctum в cookie-режиме (SPA), без JWT. Вся работа с соцсетями изолирована за `SocialProviderInterface` + реестр провайдеров: платформо-специфичной логики вне классов провайдеров нет. Отложенная публикация — Laravel Queue (Redis) + Horizon вместо Temporal: джоба ставится с `delay` до `publish_date`, идемпотентность обеспечивается state-machine поста и уникальностью джобы по `post_group`. Тариф хранится полем `organizations.plan` и проставляется вручную/сидером; проверки лимитов живут в `PlanLimitService` + middleware, а не в контроллерах.

**Платежи вне этой итерации.** Cashier, Stripe Checkout, портал, вебхуки, промокоды, trial-биллинг — исключены сознательно. Точка врезки на будущее одна: `PlanRegistry::forOrganization()` (Task 35) — сейчас читает колонку, позже начнёт читать подписку Cashier. Всё остальное кода платежей не касается.

**Tech Stack:** Laravel 13, PHP 8.3, PostgreSQL 17, Redis 7, Horizon, Sanctum, Laravel Socialite, Pest 3, Larastan (PHPStan level 6), Laravel Pint; Vue 3 + Vite + Pinia + Vue Router 4 + Tailwind 3 + FullCalendar 6 + Vitest; Docker Compose (nginx, php-fpm, node, postgres, redis, horizon, scheduler, minio, mailpit).

**Spec:** `LARAVEL_REWRITE_PLAN.md` (часть B, этапы 0–5; из этапа 10 берётся только enforcement лимитов). Дизайн-палитра: `color.md` — единственный источник цветовых значений.

---

## Global Constraints

Эти требования действуют для **каждой** задачи ниже — считайте их частью списка требований любой задачи.

- **PHP 8.3+**, **Laravel 13.x** (ветки 11.x и ранние 12.x непропатчены: CVE-2026-48019 CRLF в правиле `email` и path confusion в signed URL — фикс только в 12.60+/13.10+; `composer audit` обязан быть чистым, подавлять его запрещено), **PostgreSQL 17**, **Redis 7**. Никаких пакетов, требующих PHP < 8.2.
- **Первичные ключи — ULID** (`HasUlids`, колонка `char(26)`), не автоинкремент. Внешние ключи — `char(26)`.
- **Все деньги** — целые копейки/центы (`bigInteger`), никаких `float`.
- **Все даты в БД — UTC** (`timestamptz`). Пользовательская таймзона хранится в `users.timezone` и применяется только на отображении и в подборе слотов.
- **Токены соцсетей** шифруются через Laravel `encrypted` cast. В логи, в API-ответы, в Sentry токены не попадают никогда (`$hidden` + `Str::mask` в логах).
- **Слои backend:** `FormRequest` → `Controller` (только оркестрация, ≤ 20 строк на метод) → `Service` → `Model`. Бизнес-логика в контроллерах — повод завернуть задачу на ревью.
- **Ответы API** — только через `JsonResource`. Формат ошибок — стандартный Laravel `{"message": "...", "errors": {...}}`.
- **Мультитенантность:** каждый запрос выполняется в контексте организации. Все модели с `organization_id` используют глобальный скоуп `BelongsToOrganization`. Прямой `Model::find()` без скоупа для тенант-моделей запрещён.
- **Запрет:** `if ($provider === 'telegram')` и любые аналоги вне `app/Social/Providers/*`. Это hard-fail на ревью.
- **TDD — жёсткое правило, без исключений по существу.** В каждой задаче первый шаг — написать падающий тест, второй — **запустить его и глазами убедиться, что он падает по ожидаемой причине** (не по опечатке в импорте), и только потом писать реализацию. Реализация пишется минимальная — ровно чтобы тест позеленел.
  - Тест, написанный после кода, считается непринятой работой: он проверяет то, что получилось, а не то, что требовалось.
  - Если тест позеленел с первого запуска — это баг в тесте. Сломайте реализацию намеренно, убедитесь, что тест краснеет, верните обратно.
  - Для инфраструктурных задач (Docker, конфиг Horizon, CI) роль «падающего теста» играет исполняемая проверка из шага 1 — команда с ожидаемым выводом (`curl`, `artisan`, `grep`). Правило «сначала проверка, потом настройка» сохраняется.
  - Для фронтенда — то же самое на Vitest: сначала спека на поведение store/компонента, потом код.
- **Тесты:** Pest. Каждая задача заканчивается зелёным `./vendor/bin/pest` и `./vendor/bin/phpstan analyse`. Feature-тесты бьют по реальному API-роуту, HTTP наружу мокается через `Http::fake()`.
- **Коммиты:** Conventional Commits (`feat:`, `fix:`, `test:`, `chore:`), по коммиту на шаг «Commit» в задаче.
- **Язык:** код, комментарии, коммиты — английский. Пользовательские строки во фронтенде — через i18n-словарь (`ru`, `en`), без хардкода текста в компонентах.
- **Цвет — только из `color.md`.** Ни одного hex-значения в компонентах, ни одного цвета из дефолтной палитры Tailwind (`bg-gray-100`, `text-emerald-600` и т.п.). Разрешены только семантические классы поверх токенов: `bg-bg`, `bg-surface`, `bg-surface-2`, `border-border`, `text-text`, `text-muted`, `bg-primary`, `text-on-primary`, `bg-primary-soft`, `bg-accent`, `text-success|warning|danger`, `bg-*-soft`. Нужен новый оттенок — он сначала добавляется токеном в `color.md`, потом в `app.css`. Правила из `color.md` §3 обязательны: один primary-элемент на экран (максимум два — главная кнопка и активный пункт меню), accent не используется для кнопок-действий, три уровня фона, статусные цвета не переиспользуются под декор, статус никогда не передаётся одним лишь цветом (всегда + текст или иконка). Тёмная тема — вариант «Глубокий лес», не инверсия светлой.

---

## Структура файлов (что где живёт)

```
app/
  Http/
    Controllers/Api/          AuthController, OrganizationController, MemberController,
                              IntegrationController, PostController, CalendarController,
                              MediaController, TagController, SetController, SignatureController,
                              CommentController, BillingController, WebhookController
    Requests/                 по одному FormRequest на write-эндпоинт
    Resources/                UserResource, OrganizationResource, IntegrationResource,
                              PostResource, PostGroupResource, MediaResource, ...
    Middleware/               ResolveOrganization, EnsureOrganizationRole, EnforcePlanLimit
  Models/                     User, Organization, OrganizationUser, Invite, Integration,
                              Post, PostGroup, Media, Tag, Comment, Set, Signature, PlanUsage
  Services/
    Organizations/            OrganizationService, InviteService, MembershipService
    Integrations/             IntegrationService, TokenRefreshService
    Posts/                    PostGroupService, PostValidationService, SlotFinderService,
                              PostStateMachine
    Media/                    MediaUploadService, MediaLibraryService
    Billing/                  PlanLimitService, SubscriptionService, PlanRegistry
  Social/
    Contracts/                SocialProviderInterface, SupportsRefresh, SupportsMedia
    ProviderRegistry.php      реестр: identifier -> class
    Dto/                      AuthUrlDto, AuthResultDto, PostPayloadDto, PostResultDto,
                              ProviderCapabilities, MediaDto
    Exceptions/               ProviderAuthException, ProviderRateLimitException,
                              ProviderPermanentException, ProviderTransientException
    Providers/                TelegramProvider, MastodonProvider, BlueskyProvider,
                              DiscordProvider, SlackProvider
  Jobs/                       PublishPostJob, RefreshIntegrationTokenJob,
                              ScanMissedPostsJob, SendDigestEmailJob
  Policies/                   PostPolicy, IntegrationPolicy, MediaPolicy, OrganizationPolicy
  Support/                    Ulid helpers, TimezoneHelper
database/
  migrations/                 по миграции на связную группу таблиц
  factories/, seeders/
resources/
  js/
    app.ts, router.ts
    stores/                   auth.ts, organization.ts, integrations.ts, posts.ts, media.ts, billing.ts
    api/                      client.ts (axios + CSRF), endpoints/*.ts
    layouts/                  AppLayout.vue, AuthLayout.vue
    pages/                    Auth/*, Channels/*, Calendar/*, Media/*, Settings/*, Billing/*
    components/               calendar/, editor/, previews/, ui/
    i18n/                     ru.json, en.json
docker/
  nginx/, php/, compose.yaml
tests/
  Feature/, Unit/, Pest.php
```

**Принцип декомпозиции:** файлы, меняющиеся вместе, лежат вместе (провайдер = один класс + один тест + одна фикстура ответа). Один файл — одна ответственность; целевой потолок ~300 строк на класс.

---

## Схема данных MVP

Только то, что нужно этапам 0–5 + 10. Всё остальное (AI, аналитика, autopost, plugs, webhooks, public API) — вне MVP.

| Таблица | Ключевые поля |
|---|---|
| `users` | id ulid, email uniq, password, name, timezone, email_verified_at, api_key uniq nullable, is_superadmin bool |
| `organizations` | id, name, slug uniq, owner_id, plan enum(free/standard/pro) default `free` |
| `organization_user` | id, organization_id, user_id, role enum(SUPERADMIN/ADMIN/USER), uniq(org,user) |
| `invites` | id, organization_id, email nullable, token uniq, role, expires_at, accepted_at |
| `integrations` | id, organization_id, provider_identifier, internal_id (id аккаунта у провайдера), name, picture, profile, token (encrypted), refresh_token (encrypted), token_expires_at, settings jsonb, customer_name, group_name, disabled bool, in_between_steps bool, refresh_needed bool, deleted_at |
| `integration_time_slots` | id, integration_id, minutes_from_week_start int |
| `post_groups` | id, organization_id, state enum(DRAFT/QUEUE/PUBLISHED/ERROR), publish_date timestamptz, created_by, error text nullable, share_token uniq nullable |
| `posts` | id, post_group_id, integration_id, parent_post_id nullable, order int, content text, settings jsonb, state, release_url nullable, release_id nullable, error text nullable, published_at nullable |
| `media` | id, organization_id, name, path, disk, mime, size, width, height, thumbnail_path nullable |
| `media_post` | media_id, post_id, order |
| `tags`, `post_group_tag` | id, organization_id, name, color |
| `comments` | id, post_group_id, user_id, content, created_at |
| `sets` | id, organization_id, name, content jsonb (список integration_id) |
| `signatures` | id, organization_id, content, is_default bool |
| `plan_usages` | id, organization_id, period_start date, posts_count int, uniq(org, period_start) |

---

## Дорожная карта и зависимости

| Веха | Задачи | Блокирует |
|---|---|---|
| M0. Фундамент | 1–4 | всё |
| M1. Auth + организации | 5–10 | M2+ |
| M2. Провайдеры + каналы | 11–17 | M3, M5 |
| M3. Посты и календарь | 18–26 | M5 |
| M4. Медиа | 27–29 | M3 (медиа в посте) |
| M5. Публикация (queue) | 30–34 | — |
| M6. Лимиты тарифов | 35–36 | — |
| M7. Приёмка MVP | 37 | — |

M4 можно вести параллельно с M3 после задачи 20. M6 не зависит от M2–M5 и может идти параллельно вторым потоком.

---

# M0. Фундамент

### Task 1: Docker-окружение и каркас Laravel

**Files:**
- Create: `docker/compose.yaml`, `docker/php/Dockerfile`, `docker/php/php.ini`, `docker/nginx/default.conf`, `Makefile`, `.env.example`
- Create: скелет Laravel 13 в корне (`composer create-project laravel/laravel:^13.0 .`)

**Interfaces:**
- Produces: `make up`, `make down`, `make sh`, `make test`, `make migrate` — этими командами пользуются все последующие задачи.

- [ ] **Step 1: Создать скелет Laravel**

```bash
composer create-project laravel/laravel:^13.0 app-tmp && rsync -a app-tmp/ ./ && rm -rf app-tmp
composer require laravel/sanctum laravel/horizon laravel/socialite predis/predis
composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan laravel/pint
```

- [ ] **Step 2: Написать `docker/compose.yaml`**

```yaml
services:
  nginx:
    image: nginx:1.27-alpine
    ports: ["8080:80"]
    volumes:
      - ../:/var/www/html
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on: [php]
  php:
    build: ./php
    volumes: ["../:/var/www/html"]
    depends_on: [postgres, redis]
    env_file: ../.env
  horizon:
    build: ./php
    command: php artisan horizon
    volumes: ["../:/var/www/html"]
    depends_on: [redis, postgres]
    env_file: ../.env
  scheduler:
    build: ./php
    command: sh -c "while true; do php artisan schedule:run; sleep 60; done"
    volumes: ["../:/var/www/html"]
    depends_on: [redis, postgres]
    env_file: ../.env
  node:
    image: node:22-alpine
    working_dir: /var/www/html
    command: sh -c "npm install && npm run dev -- --host"
    ports: ["5173:5173"]
    volumes: ["../:/var/www/html"]
  postgres:
    image: postgres:17-alpine
    environment:
      POSTGRES_DB: app
      POSTGRES_USER: app
      POSTGRES_PASSWORD: secret
    ports: ["5432:5432"]
    volumes: ["pgdata:/var/lib/postgresql/data"]
  redis:
    image: redis:7-alpine
    ports: ["6379:6379"]
  minio:
    image: minio/minio
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: minio
      MINIO_ROOT_PASSWORD: minio123
    ports: ["9000:9000", "9001:9001"]
    volumes: ["minio:/data"]
  mailpit:
    image: axllent/mailpit
    ports: ["8025:8025", "1025:1025"]
volumes: { pgdata: {}, minio: {} }
```

- [ ] **Step 3: `.env.example` — ключевые значения**

```dotenv
APP_URL=http://localhost:8080
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_DATABASE=app
DB_USERNAME=app
DB_PASSWORD=secret
REDIS_HOST=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_DOMAIN=localhost
SANCTUM_STATEFUL_DOMAINS=localhost:8080,localhost:5173
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
FILESYSTEM_DISK=s3
AWS_ENDPOINT=http://minio:9000
AWS_BUCKET=media
AWS_USE_PATH_STYLE_ENDPOINT=true
```

- [ ] **Step 4: До сборки — зафиксировать ожидаемую проверку и убедиться, что она НЕ проходит**

Run: `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080`
Expected: `000` (connection refused) — окружение ещё не поднято.

- [ ] **Step 5: Поднять окружение и повторить ту же проверку**

Run: `make up && curl -s -o /dev/null -w "%{http_code}" http://localhost:8080`
Expected: `200`

- [ ] **Step 6: Commit**

```bash
git add . && git commit -m "chore: bootstrap laravel 11 skeleton and docker environment"
```

---

### Task 2: Тестовый стенд, статанализ, CI

**Files:**
- Create: `tests/Pest.php`, `phpstan.neon`, `pint.json`, `.github/workflows/ci.yml`
- Modify: `phpunit.xml`

**Interfaces:**
- Produces: `./vendor/bin/pest`, `./vendor/bin/phpstan analyse`, `./vendor/bin/pint --test` — используются в каждой задаче.

- [ ] **Step 1: Написать падающий смоук-тест**

```php
// tests/Feature/SmokeTest.php
it('boots the application', function () {
    $this->get('/up')->assertOk();
});
```

- [ ] **Step 2: Запустить, убедиться что падает (Pest ещё не настроен)**

Run: `./vendor/bin/pest tests/Feature/SmokeTest.php`
Expected: FAIL — «Pest.php not configured» / нет `RefreshDatabase`.

- [ ] **Step 3: Настроить `tests/Pest.php`**

```php
<?php
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(Tests\TestCase::class)->in('Unit');

function actingAsMember(\App\Models\Organization $org, string $role = 'ADMIN'): \App\Models\User
{
    $user = \App\Models\User::factory()->create();
    $org->members()->attach($user, ['id' => (string) \Illuminate\Support\Str::ulid(), 'role' => $role]);
    test()->actingAs($user);
    test()->withHeader('X-Organization', $org->id);
    return $user;
}
```

- [ ] **Step 4: `phpstan.neon`**

```neon
includes: [vendor/larastan/larastan/extension.neon]
parameters:
  level: 6
  paths: [app, database, tests]
```

- [ ] **Step 5: Прогнать тесты и анализ**

Run: `./vendor/bin/pest && ./vendor/bin/phpstan analyse && ./vendor/bin/pint --test`
Expected: всё зелёное.

- [ ] **Step 6: CI (`.github/workflows/ci.yml`) — postgres+redis сервисы, шаги: `composer install`, `pint --test`, `phpstan`, `pest`, `npm ci && npm run build && npx vitest run`**

- [ ] **Step 7: Commit** — `chore: add pest, phpstan, pint and CI pipeline`

---

### Task 3: Vue SPA-каркас, роутинг, дизайн-система на палитре `color.md`

**Files:**
- Create: `resources/js/app.ts`, `router.ts`, `layouts/AppLayout.vue`, `layouts/AuthLayout.vue`, `api/client.ts`, `i18n/{ru,en}.json`, `components/ui/{Button,Input,Modal,Card,Badge,Toast,ThemeToggle}.vue`, `composables/useTheme.ts`
- Create: `resources/css/app.css` (CSS-переменные обеих тем)
- Modify: `vite.config.ts`, `tailwind.config.js`, `resources/views/app.blade.php`, `routes/web.php`

**Interfaces:**
- Produces: `api` (axios-инстанс с `withCredentials: true` и авто-`/sanctum/csrf-cookie`), `router` с guard'ом `requiresAuth`, UI-кит на семантических токенах, `useTheme()` → `{ theme, toggle() }` (значение в `localStorage`, дефолт — системный `prefers-color-scheme`).

**Источник цвета:** `color.md`. Hex-значения переносятся **только** в `resources/css/app.css`; ни один компонент hex не содержит. Три уровня фона (`bg` → `surface` → `surface-2`) обязательны с самого начала — иначе сайдбар слипнется с контентом и переделывать придётся весь layout.

- [ ] **Step 1: Catch-all роут отдаёт SPA**

```php
// routes/web.php
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|horizon).*$');
```

- [ ] **Step 2: `resources/css/app.css` — токены из `color.md` §1 и §2**

```css
@tailwind base; @tailwind components; @tailwind utilities;

:root {
  --bg: #F1F5F2;           --surface: #FFFFFF;      --surface-2: #F7FAF8;
  --border: #DBE5DD;       --text: #0B1F17;         --muted: #5A7267;
  --primary: #0F726B;      --primary-hover: #0C5B55;
  --on-primary: #FFFFFF;   --primary-soft: #D5EDEA;
  --accent: #A94E08;       --on-accent: #FFFFFF;    --accent-soft: #F7E9D5;
  --success: #147839;      --success-soft: #D8F0DF;
  --warning: #896605;      --warning-soft: #FAF0D2;
  --danger:  #B91C1C;      --danger-soft:  #FADEDE;
}

.dark {                    /* «Глубокий лес» — не инверсия светлой темы */
  --bg: #07110E;           --surface: #0F1D18;      --surface-2: #0A1613;
  --border: #1E332B;       --text: #E4EFE9;         --muted: #8AA598;
  --primary: #2DD4BF;      --primary-hover: #5EEAD4;
  --on-primary: #032620;   --primary-soft: #0F332D;
  --accent: #FB923C;       --on-accent: #2A1206;    --accent-soft: #38230F;
  --success: #4ADE80;      --success-soft: #123120;
  --warning: #FCD34D;      --warning-soft: #332A0B;
  --danger:  #F87171;      --danger-soft:  #38191B;
}

body { background: var(--bg); color: var(--text); }
```

- [ ] **Step 3: `tailwind.config.js` — семантические классы поверх переменных**

```js
export default {
  darkMode: 'class',
  content: ['./resources/**/*.{vue,ts,blade.php}'],
  theme: {
    extend: {
      colors: {
        bg: 'var(--bg)', surface: 'var(--surface)', 'surface-2': 'var(--surface-2)',
        border: 'var(--border)', text: 'var(--text)', muted: 'var(--muted)',
        primary: { DEFAULT: 'var(--primary)', hover: 'var(--primary-hover)', soft: 'var(--primary-soft)' },
        'on-primary': 'var(--on-primary)',
        accent: { DEFAULT: 'var(--accent)', soft: 'var(--accent-soft)' },
        'on-accent': 'var(--on-accent)',
        success: { DEFAULT: 'var(--success)', soft: 'var(--success-soft)' },
        warning: { DEFAULT: 'var(--warning)', soft: 'var(--warning-soft)' },
        danger:  { DEFAULT: 'var(--danger)',  soft: 'var(--danger-soft)'  },
      },
      fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
      borderRadius: { xl: '0.75rem' },
    },
  },
}
```

- [ ] **Step 4: `api/client.ts`**

```ts
import axios from 'axios'
export const api = axios.create({ baseURL: '/api', withCredentials: true })
let csrfReady: Promise<unknown> | null = null
api.interceptors.request.use(async (config) => {
  if (['post','put','patch','delete'].includes(config.method ?? '')) {
    csrfReady ??= axios.get('/sanctum/csrf-cookie')
    await csrfReady
  }
  const org = localStorage.getItem('organization_id')
  if (org) config.headers['X-Organization'] = org
  return config
})
```

- [ ] **Step 5: Сначала тесты — поведение UI-кита и дисциплина палитры**

```ts
// resources/js/components/ui/__tests__/Button.spec.ts
import { mount } from '@vue/test-utils'
import Button from '../Button.vue'

it('emits click when not loading', async () => {
  const w = mount(Button, { props: { loading: false } })
  await w.trigger('click')
  expect(w.emitted('click')).toBeTruthy()
})

it('does not emit click while loading', async () => {
  const w = mount(Button, { props: { loading: true } })
  await w.trigger('click')
  expect(w.emitted('click')).toBeFalsy()
})

it('renders the primary variant on semantic tokens', () => {
  const w = mount(Button, { props: { variant: 'primary' } })
  expect(w.classes()).toContain('bg-primary')
  expect(w.classes()).toContain('text-on-primary')
})

it('renders the secondary variant as an outline, never as accent', () => {
  const w = mount(Button, { props: { variant: 'secondary' } })
  expect(w.classes()).toEqual(expect.arrayContaining(['border-border', 'text-text']))
  expect(w.classes().join(' ')).not.toMatch(/accent|primary/)   // color.md §3.2
})
```

```ts
// resources/js/__tests__/palette.spec.ts — сторож палитры на весь фронтенд
import { readFileSync } from 'node:fs'
import { globSync } from 'glob'

const sources = globSync('resources/js/**/*.vue')

it('has no raw hex colors in components', () => {
  const offenders = sources.filter(f => /#[0-9a-fA-F]{3,8}\b/.test(readFileSync(f, 'utf8')))
  expect(offenders).toEqual([])
})

it('has no default tailwind palette classes in components', () => {
  const banned = /\b(?:bg|text|border|ring)-(?:gray|slate|zinc|neutral|stone|emerald|teal|green|red|amber|yellow|orange|blue|indigo)-\d{2,3}\b/
  const offenders = sources.filter(f => banned.test(readFileSync(f, 'utf8')))
  expect(offenders).toEqual([])
})
```

- [ ] **Step 6: Запустить `npx vitest run`** → FAIL (компонентов ещё нет).

- [ ] **Step 7: Реализовать `app.ts`, роутер, layout'ы, `useTheme`, UI-кит** — минимально, чтобы спеки позеленели. `Button` варианты: `primary` (`bg-primary text-on-primary hover:bg-primary-hover`), `secondary` (`border border-border text-text`), `ghost` (`text-muted`), `danger` (`bg-danger text-white`). `Badge` варианты повторяют статусы из `color.md` §1 и **всегда** содержат текст, не только цвет.

- [ ] **Step 8: `npx vitest run`** → PASS.

- [ ] **Step 9: Ручная проверка палитры** — открыть `/login` в светлой и тёмной теме, переключателем сравнить: три уровня фона различимы, вторичный текст читается, на экране не более двух primary-элементов.

- [ ] **Step 10: Commit** — `feat: add vue spa shell, router, api client and design tokens`

---

### Task 4: Базовые трейты мультитенантности

**Files:**
- Create: `app/Models/Concerns/BelongsToOrganization.php`, `app/Support/OrganizationContext.php`, `app/Http/Middleware/ResolveOrganization.php`
- Test: `tests/Unit/BelongsToOrganizationTest.php`

**Interfaces:**
- Produces:
  - `OrganizationContext::set(Organization $org): void`, `::get(): ?Organization`, `::id(): ?string`
  - трейт `BelongsToOrganization` — глобальный скоуп по `organization_id` + авто-заполнение при создании
  - middleware alias `organization` — читает `X-Organization`, проверяет членство, кладёт в контекст, 403 если не член.

- [ ] **Step 1: Написать падающий тест**

```php
it('scopes queries to the current organization', function () {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    Tag::factory()->for($a)->create(['name' => 'a']);
    Tag::factory()->for($b)->create(['name' => 'b']);

    OrganizationContext::set($a);
    expect(Tag::pluck('name')->all())->toBe(['a']);
});

it('auto-fills organization_id on create', function () {
    $a = Organization::factory()->create();
    OrganizationContext::set($a);
    expect(Tag::create(['name' => 'x', 'color' => '#fff'])->organization_id)->toBe($a->id);
});
```

- [ ] **Step 2: Запустить** → FAIL (`Class BelongsToOrganization not found`).

- [ ] **Step 3: Реализовать трейт**

```php
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $q) {
            if ($id = OrganizationContext::id()) {
                $q->where($q->getModel()->getTable().'.organization_id', $id);
            }
        });
        static::creating(function ($model) {
            $model->organization_id ??= OrganizationContext::id();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

- [ ] **Step 4: Запустить тесты** → PASS.

- [ ] **Step 5: Commit** — `feat: add multi-tenant organization scope`

---

# M1. Аутентификация и организации

### Task 5: Миграции users / organizations / organization_user / invites

**Files:**
- Create: `database/migrations/*_create_organizations_table.php`, `*_create_organization_user_table.php`, `*_create_invites_table.php`
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`
- Create: `app/Models/{Organization,OrganizationUser,Invite}.php`, фабрики
- Test: `tests/Unit/OrganizationModelTest.php`

**Interfaces:**
- Produces: `User::organizations()`, `Organization::members()`, `Organization::roleOf(User): ?string`, enum `App\Enums\OrganizationRole {SUPERADMIN, ADMIN, USER}`.

- [ ] **Step 1: Тест на связи и роль**

```php
it('returns the role of a member', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->members()->attach($user, ['id' => (string) Str::ulid(), 'role' => 'ADMIN']);
    expect($org->roleOf($user))->toBe(OrganizationRole::ADMIN);
    expect($org->roleOf(User::factory()->create()))->toBeNull();
});
```

- [ ] **Step 2: Запустить** → FAIL.

- [ ] **Step 3: Миграции** (`users`: ulid PK, `timezone` default `UTC`, `api_key` nullable uniq, `is_superadmin` default false; `organizations`: ulid, name, slug uniq, owner_id FK; `organization_user`: ulid, уникальный индекс `(organization_id, user_id)`; `invites`: token uniq, expires_at).

- [ ] **Step 4: Модели + фабрики, `roleOf` через `members()->where('user_id',...)->first()?->pivot->role`.**

- [ ] **Step 5: `php artisan migrate:fresh && ./vendor/bin/pest tests/Unit/OrganizationModelTest.php`** → PASS.

- [ ] **Step 6: Commit** — `feat: add organizations, memberships and invites schema`

---

### Task 6: Регистрация, логин, выход (Sanctum SPA)

**Files:**
- Create: `app/Http/Controllers/Api/AuthController.php`, `app/Http/Requests/{RegisterRequest,LoginRequest}.php`, `app/Http/Resources/UserResource.php`, `app/Services/Organizations/OrganizationService.php`
- Modify: `routes/api.php`, `bootstrap/app.php` (middleware `EnsureFrontendRequestsAreStateful`)
- Test: `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/Auth/LoginTest.php`

**Interfaces:**
- Consumes: модели из Task 5.
- Produces:
  - `POST /api/auth/register {name,email,password}` → 201 `{user, organization}`; создаёт организацию «{name}'s workspace» и membership с ролью `SUPERADMIN`
  - `POST /api/auth/login {email,password}` → 200 `{user}`
  - `POST /api/auth/logout` → 204
  - `GET /api/auth/me` → 200 `{user, organizations[]}`
  - `OrganizationService::createForUser(User $user, string $name): Organization`

- [ ] **Step 1: Падающие тесты**

```php
it('registers a user and creates their first organization', function () {
    $res = $this->postJson('/api/auth/register', [
        'name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret1234',
    ])->assertCreated();

    $user = User::firstWhere('email', 'ann@example.com');
    expect($user)->not->toBeNull()
        ->and($user->organizations)->toHaveCount(1)
        ->and($user->organizations->first()->roleOf($user))->toBe(OrganizationRole::SUPERADMIN);
    $res->assertJsonPath('user.email', 'ann@example.com')
        ->assertJsonMissingPath('user.password');
});

it('rejects duplicate emails', function () {
    User::factory()->create(['email' => 'ann@example.com']);
    $this->postJson('/api/auth/register', ['name'=>'A','email'=>'ann@example.com','password'=>'secret1234'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
});

it('logs in and returns the session user', function () {
    User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('secret1234')]);
    $this->postJson('/api/auth/login', ['email'=>'ann@example.com','password'=>'secret1234'])->assertOk();
    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', 'ann@example.com');
});
```

- [ ] **Step 2: Запустить** → FAIL (404 на роутах).

- [ ] **Step 3: Реализовать контроллер, реквесты, ресурс, регистрация роутов; `RegisterRequest` — `password` `Password::min(8)`; всё внутри `DB::transaction`.**

- [ ] **Step 4: Тесты** → PASS.

- [ ] **Step 5: Commit** — `feat: add registration, login and session endpoints`

---

### Task 7: Верификация email, восстановление пароля

**Files:**
- Create: `app/Http/Controllers/Api/{EmailVerificationController,PasswordResetController}.php`, `app/Notifications/{VerifyEmailNotification,ResetPasswordNotification}.php`
- Modify: `app/Models/User.php` (`implements MustVerifyEmail`), `routes/api.php`
- Test: `tests/Feature/Auth/EmailVerificationTest.php`, `tests/Feature/Auth/PasswordResetTest.php`

**Interfaces:**
- Produces: `POST /api/auth/email/resend`, `GET /api/auth/email/verify/{id}/{hash}` (signed), `POST /api/auth/password/forgot`, `POST /api/auth/password/reset`.

- [ ] **Step 1: Тесты с `Notification::fake()`** — письмо отправлено при регистрации; подписанная ссылка проставляет `email_verified_at`; протухшая подпись → 403; сброс пароля меняет хэш и инвалидирует токен повторно.

- [ ] **Step 2: Запустить** → FAIL.

- [ ] **Step 3: Реализовать. Письма кладутся в очередь (`ShouldQueue`), шаблоны — markdown-mail на двух языках по `users.locale`.**

- [ ] **Step 4: Тесты** → PASS. Ручная проверка писем в Mailpit (`http://localhost:8025`).

- [ ] **Step 5: Commit** — `feat: add email verification and password reset`

---

### Task 8: Соц-логин (Socialite: GitHub, Google, generic OIDC)

**Files:**
- Create: `app/Http/Controllers/Api/SocialAuthController.php`, `app/Services/Auth/SocialAccountService.php`, миграция `social_accounts`
- Modify: `config/services.php`
- Test: `tests/Feature/Auth/SocialLoginTest.php`

**Interfaces:**
- Produces: `GET /api/auth/oauth/{driver}/redirect` → `{url}`; `GET /api/auth/oauth/{driver}/callback` → сессия + редирект на SPA; `SocialAccountService::findOrCreateUser(string $driver, SocialiteUser $u): User`.
- Правило: если email совпал с существующим пользователем — привязываем провайдера к нему, а не создаём дубль.

- [ ] **Step 1: Тест с мок-драйвером Socialite** (`Socialite::shouldReceive('driver->user')->andReturn($fakeUser)`): новый email → создан пользователь + организация; существующий email → привязка без дубля.

- [ ] **Step 2: Запустить** → FAIL.

- [ ] **Step 3: Реализовать. `driver` валидируется белым списком `['github','google','oidc']`.**

- [ ] **Step 4: Тесты** → PASS.

- [ ] **Step 5: Commit** — `feat: add social login via socialite`

---

### Task 9: Организации — переключение, приглашения, роли

**Files:**
- Create: `app/Http/Controllers/Api/{OrganizationController,MemberController,InviteController}.php`, `app/Services/Organizations/{InviteService,MembershipService}.php`, `app/Http/Middleware/EnsureOrganizationRole.php`, `app/Policies/OrganizationPolicy.php`
- Modify: `routes/api.php`, `bootstrap/app.php`
- Test: `tests/Feature/Organizations/{SwitchOrganizationTest,InviteTest,RoleTest}.php`

**Interfaces:**
- Consumes: `ResolveOrganization` (Task 4), модели (Task 5).
- Produces:
  - `GET /api/organizations`, `POST /api/organizations {name}`, `PUT /api/organizations/current {name, shortlink_provider}`
  - `GET /api/organizations/current/members`, `DELETE /api/organizations/current/members/{user}`, `PUT .../members/{user}/role {role}`
  - `POST /api/organizations/current/invites {email,role}` → `{url}`; `POST /api/invites/{token}/accept`
  - middleware `role:ADMIN` — 403 для `USER`.

- [ ] **Step 1: Падающие тесты**

```php
it('forbids a USER role from inviting members', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'USER');
    $this->postJson('/api/organizations/current/invites', ['email'=>'x@e.com','role'=>'USER'])
        ->assertForbidden();
});

it('adds the invitee as a member on accept', function () {
    $org = Organization::factory()->create();
    $invite = Invite::factory()->for($org)->create(['role' => 'USER', 'expires_at' => now()->addDay()]);
    $user = User::factory()->create();
    $this->actingAs($user)->postJson("/api/invites/{$invite->token}/accept")->assertOk();
    expect($org->fresh()->roleOf($user))->toBe(OrganizationRole::USER);
});

it('rejects an expired invite', function () { /* expires_at = now()->subMinute() → 410 */ });

it('blocks access to another organization', function () {
    $mine = Organization::factory()->create();
    $other = Organization::factory()->create();
    actingAsMember($mine);
    $this->withHeader('X-Organization', $other->id)->getJson('/api/organizations/current/members')
        ->assertForbidden();
});
```

- [ ] **Step 2: Запустить** → FAIL.

- [ ] **Step 3: Реализовать. Последнего `SUPERADMIN` удалить/понизить нельзя — 422 с внятным сообщением.**

- [ ] **Step 4: Тесты** → PASS.

- [ ] **Step 5: Commit** — `feat: add organization switching, invites and role enforcement`

---

### Task 10: Профиль, API-ключ, impersonation; фронтенд auth-флоу

**Files:**
- Create: `app/Http/Controllers/Api/{ProfileController,ImpersonationController}.php`
- Create: `resources/js/pages/Auth/{Login,Register,Forgot,Reset}.vue`, `pages/Settings/Profile.vue`, `pages/Settings/Team.vue`, `stores/{auth,organization}.ts`, `components/OrganizationSwitcher.vue`
- Test: `tests/Feature/ProfileTest.php`, `resources/js/stores/__tests__/auth.spec.ts`

**Interfaces:**
- Produces: `PUT /api/profile {name,timezone,locale,email_notifications}`, `POST /api/profile/api-key/rotate` → `{api_key}`, `POST /api/admin/impersonate/{user}` (только `is_superadmin`), `POST /api/admin/impersonate/stop`.
- Frontend: `useAuthStore().user`, `.login()`, `.logout()`, `.fetchMe()`; `useOrganizationStore().current`, `.switchTo(id)` (пишет `organization_id` в localStorage и перезагружает данные).

- [ ] **Step 1: Тесты бэка** — ротация ключа меняет значение и инвалидирует старое; не-суперадмин получает 403 на impersonate.
- [ ] **Step 2: Запустить** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Vitest на `auth` store** — `login()` заполняет `user`, `logout()` очищает и редиректит на `/login`.
- [ ] **Step 6: Ручная проверка:** регистрация → письмо в Mailpit → верификация → вход → переключение организации.
- [ ] **Step 7: Commit** — `feat: add profile settings, api key rotation, impersonation and auth ui`

---

# M2. Каналы (Integrations)

### Task 11: Абстракция провайдера — контракты, DTO, реестр

**Files:**
- Create: `app/Social/Contracts/{SocialProviderInterface,SupportsRefresh,SupportsMedia}.php`, `app/Social/Dto/*.php`, `app/Social/Exceptions/*.php`, `app/Social/ProviderRegistry.php`, `app/Providers/SocialServiceProvider.php`
- Test: `tests/Unit/Social/ProviderRegistryTest.php`

**Interfaces:**
- Produces (это контракт для задач 12–16 и 30 — воспроизводить дословно):

```php
interface SocialProviderInterface
{
    public function identifier(): string;                 // 'telegram'
    public function name(): string;                       // 'Telegram'
    public function capabilities(): ProviderCapabilities;
    public function generateAuthUrl(string $state, string $redirectUri): AuthUrlDto;
    public function authenticate(array $params, string $redirectUri): AuthResultDto;
    /**
     * @param  PostPayloadDto[] $chain  тред: первый элемент — корень
     * @return array<int, PostResultDto>  по одному результату на элемент цепочки, ключ = индекс в $chain
     */
    public function post(Integration $integration, array $chain): array;
}

interface SupportsRefresh { public function refresh(Integration $i): AuthResultDto; }

/** Двухфазная загрузка: сначала uploadMedia(), затем post() ссылается на MediaDto::$remoteId. */
interface SupportsMedia { public function uploadMedia(Integration $i, MediaDto $media): MediaDto; }

final readonly class ProviderCapabilities {
    public function __construct(
        public int $maxCharacters,          // 4096
        public int $maxImages,              // 10, 0 = не поддерживает
        public int $maxVideos,
        public bool $supportsThreads,
        public bool $supportsScheduling,
        public array $allowedMimeTypes,     // ['image/jpeg','image/png','video/mp4']
        public int $maxFileSizeBytes,
        public bool $allowsMixedMedia,      // можно ли смешивать фото и видео в одном посте
        public ?int $maxThreadLength,       // null = без ограничения
        public bool $requiresAltText,
    ) {}
}

final readonly class AuthResultDto {
    public function __construct(
        public string $internalId, public string $name, public string $token,
        public ?string $refreshToken = null, public ?CarbonImmutable $expiresAt = null,
        public ?string $picture = null, public array $settings = [],
    ) {}
}

final readonly class PostPayloadDto {
    /** @param MediaDto[] $media */
    public function __construct(
        public string $content,
        public array $media = [],
        public array $settings = [],
        /**
         * Якорь треда: id уже опубликованного поста на стороне провайдера.
         * Если задан — сегмент должен уйти ОТВЕТОМ на него, а не новым корнем треда.
         * Пайплайн ставит его только на первый элемент возобновляемой цепочки (после
         * ProviderPartialFailureException); внутри одного вызова post() провайдер связывает
         * сегменты сам по своим же результатам. Провайдер, чья сеть не умеет отвечать на
         * произвольный внешний id, обязан бросить ProviderPermanentException — молчаливая
         * публикация «отвязанного» поста хуже отказа.
         */
        public ?string $replyToReleaseId = null,
    ) {}
}

final readonly class PostResultDto {
    public function __construct(public string $releaseId, public ?string $releaseUrl = null) {}
}

final readonly class MediaDto {
    public function __construct(
        public string $path,                // локальный путь до загрузки
        public string $mimeType,
        public ?string $altText = null,
        public ?string $remoteId = null,    // хэндл, выданный провайдером в uploadMedia()
    ) {}
    public function withRemoteId(string $remoteId): self { /* immutable clone */ }
}
```

- Исключения (все наследуют базовый `ProviderException`, чтобы у пайплайна был fallback-catch):
  `ProviderTransientException` (ретраить), `ProviderPermanentException` (не ретраить, пост → ERROR),
  `ProviderAuthException` (пометить канал `refresh_needed`), `ProviderRateLimitException` (`retryAfterSeconds`),
  `ProviderPartialFailureException` — часть цепочки опубликовалась: несёт `array<int, PostResultDto>` уже
  опубликованных сегментов. Пайплайн обязан сохранить их `release_id` и **никогда** не переотправлять сегмент,
  у которого `release_id` уже есть, иначе ретрай продублирует пост в сети.
- `ProviderRegistry::get(string $identifier): SocialProviderInterface`, `::all(): array`, `::has(string): bool`. Неизвестный идентификатор → `UnknownProviderException`.

- [ ] **Step 1: Тест реестра**

```php
it('resolves a registered provider by identifier', function () {
    expect(app(ProviderRegistry::class)->get('telegram'))->toBeInstanceOf(TelegramProvider::class);
});
it('throws for an unknown provider', function () {
    app(ProviderRegistry::class)->get('myspace');
})->throws(UnknownProviderException::class);
```

- [ ] **Step 2: Запустить** → FAIL. **Step 3:** Реализовать контракты, DTO, реестр (список классов — из `config/social.php`). **Step 4:** → PASS.

- [ ] **Step 5: Архитектурный тест** — hard-fail правило из Global Constraints:

```php
// tests/Unit/Architecture/ProviderIsolationTest.php
it('has no provider-specific branching outside app/Social/Providers', function () {
    $hits = [];
    foreach (['telegram','mastodon','bluesky','discord','slack'] as $p) {
        $out = shell_exec("grep -rniE \"['\\\"]{$p}['\\\"]\" ".base_path('app')." --include=*.php "
            ."| grep -v 'app/Social/Providers' | grep -v 'config/social.php' || true");
        if (trim((string) $out) !== '') { $hits[] = $out; }
    }
    expect($hits)->toBe([]);
});
```

- [ ] **Step 6: Commit** — `feat: add social provider contracts, dtos and registry`

---

### Task 12: Схема integrations + сервис подключения

**Files:**
- Create: миграции `integrations`, `integration_time_slots`; `app/Models/Integration.php`, `app/Services/Integrations/IntegrationService.php`, `app/Policies/IntegrationPolicy.php`
- Test: `tests/Feature/Integrations/ConnectIntegrationTest.php`

**Interfaces:**
- Produces:
  - `IntegrationService::connect(Organization $org, string $providerIdentifier, AuthResultDto $auth): Integration` — upsert по `(organization_id, provider_identifier, internal_id)`: повторное подключение обновляет токены и снимает `refresh_needed`, не плодит дубли.
  - `IntegrationService::disable/enable/softDelete(Integration $i): void`
  - Модель: casts `token` и `refresh_token` — `encrypted`; `$hidden = ['token','refresh_token']`.

- [ ] **Step 1: Тесты**

```php
it('encrypts tokens at rest', function () {
    $i = Integration::factory()->create(['token' => 'plain-secret']);
    $raw = DB::table('integrations')->where('id', $i->id)->value('token');
    expect($raw)->not->toContain('plain-secret')
        ->and($i->fresh()->token)->toBe('plain-secret');
});

it('never exposes tokens through the api resource', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    Integration::factory()->for($org)->create(['token' => 'plain-secret']);
    $this->getJson('/api/integrations')->assertOk()->assertDontSee('plain-secret');
});

it('updates an existing integration on reconnect instead of duplicating', function () {
    $org = Organization::factory()->create();
    $svc = app(IntegrationService::class);
    $dto = new AuthResultDto('acc-1', 'Chan', 'tok-1');
    $svc->connect($org, 'telegram', $dto);
    $svc->connect($org, 'telegram', new AuthResultDto('acc-1', 'Chan', 'tok-2'));
    expect(Integration::withoutGlobalScopes()->count())->toBe(1)
        ->and(Integration::withoutGlobalScopes()->first()->token)->toBe('tok-2');
});
```

- [ ] **Step 2: Запустить** → FAIL. **Step 3:** Миграции, модель, сервис, политика. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add integrations schema with encrypted token storage`

---

### Task 13: OAuth-флоу подключения канала (API)

**Files:**
- Create: `app/Http/Controllers/Api/IntegrationController.php`, `app/Http/Requests/ConnectIntegrationRequest.php`, `app/Http/Resources/IntegrationResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Integrations/IntegrationApiTest.php`

**Interfaces:**
- Produces:
  - `GET /api/providers` → `[{identifier, name, capabilities, auth_type}]`
  - `GET /api/integrations` → список каналов организации
  - `POST /api/integrations/{provider}/auth-url` → `{url, state}` (state в кэше 10 мин, привязан к org+user)
  - `POST /api/integrations/{provider}/callback {state, code|token|...}` → `IntegrationResource`
  - `PUT /api/integrations/{id} {name, group_name, customer_name, settings, time_slots[]}`
  - `POST /api/integrations/{id}/disable|enable`, `DELETE /api/integrations/{id}`

- [ ] **Step 1: Тесты** — чужой `state` → 422; успешный callback создаёт канал; `DELETE` делает soft-delete и канал исчезает из списка; `PUT` c `time_slots` сохраняет слоты.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать (провайдер берётся строго из `ProviderRegistry`). **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add integration connect/manage api`

---

### Task 14: Провайдеры первой волны — Telegram и Mastodon

**Files:**
- Create: `app/Social/Providers/{TelegramProvider,MastodonProvider}.php`
- Test: `tests/Unit/Social/{TelegramProviderTest,MastodonProviderTest}.php`, фикстуры `tests/Fixtures/social/*.json`

**Interfaces:**
- Consumes: контракты Task 11.
- Produces: записи в `config/social.php`.
- Telegram: авторизация — не OAuth, а бот-токен + `chat_id` (`auth_type = 'credentials'`), `authenticate(['bot_token'=>..,'chat_id'=>..])` дергает `getChat` для проверки. Публикация: `sendMessage`, при медиа — `sendPhoto`/`sendMediaGroup`. Лимиты: 4096 символов, 10 изображений, треды — да (через `reply_to_message_id`).
- Mastodon: стандартный OAuth2 с указанием инстанса; `POST /api/v1/statuses`, медиа через `/api/v2/media`; 500 символов, 4 изображения, треды через `in_reply_to_id`.

- [ ] **Step 1: Тесты с `Http::fake()`**

```php
it('publishes a telegram message and returns the release id', function () {
    Http::fake(['api.telegram.org/*' => Http::response(
        json_decode(file_get_contents(base_path('tests/Fixtures/social/telegram_send_message.json')), true)
    )]);
    $integration = Integration::factory()->create(['provider_identifier'=>'telegram','token'=>'bot:1','settings'=>['chat_id'=>'-100']]);
    $result = app(ProviderRegistry::class)->get('telegram')
        ->post($integration, [new PostPayloadDto('hello')]);
    expect($result->releaseId)->toBe('42');
});

it('maps a telegram 429 to a rate limit exception', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['parameters'=>['retry_after'=>30]], 429)]);
    // ...
})->throws(ProviderRateLimitException::class);

it('maps a telegram 401 to an auth exception', function () { /* 401 → ProviderAuthException */ });

it('rejects content over the mastodon character limit', function () { /* 501 симв. → ProviderPermanentException */ });
```

- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать оба провайдера, маппинг HTTP-кодов на исключения вынести в трейт `MapsHttpErrors`. **Step 4:** → PASS.
- [ ] **Step 5: Ручная проверка** с реальным тестовым ботом Telegram и аккаунтом на `mastodon.social`.
- [ ] **Step 6: Commit** — `feat: add telegram and mastodon providers`

---

### Task 15: Провайдеры — Bluesky, Discord, Slack

**Files:**
- Create: `app/Social/Providers/{BlueskyProvider,DiscordProvider,SlackProvider}.php`
- Test: соответствующие Unit-тесты + фикстуры

**Interfaces:**
- Bluesky: `com.atproto.server.createSession` (handle + app password), публикация `com.atproto.repo.createRecord`, блобы через `uploadBlob`; 300 символов, 4 изображения, треды через `reply.root/parent`; сессия обновляется через `refreshSession` → реализует `SupportsRefresh`.
- Discord: OAuth2 бота с `webhook.incoming`; публикация в webhook канала; 2000 символов, 10 вложений, треды — нет (`supportsThreads=false`), последующие сообщения чейна шлём отдельными сообщениями подряд.
- Slack: OAuth2 (`chat:write`, `files:write`, `channels:read`), `chat.postMessage`, медиа через `files.getUploadURLExternal` + `files.completeUploadExternal`; треды через `thread_ts`; выбор канала — per-provider настройка (`settings.channel_id`), список каналов отдаётся эндпоинтом `GET /api/integrations/{id}/options/channels`.

- [ ] **Step 1: Тесты** — по каждому провайдеру: успешная публикация, тред, превышение лимита символов, 401 → `ProviderAuthException`.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5:** Прогнать архитектурный тест из Task 11 → PASS.
- [ ] **Step 6: Commit** — `feat: add bluesky, discord and slack providers`

---

### Task 16: Refresh токенов и «сломанные» каналы

**Files:**
- Create: `app/Jobs/RefreshIntegrationTokenJob.php`, `app/Console/Commands/ScheduleTokenRefresh.php`, `app/Services/Integrations/TokenRefreshService.php`, `app/Notifications/IntegrationBrokenNotification.php`
- Modify: `routes/console.php` (schedule)
- Test: `tests/Feature/Integrations/TokenRefreshTest.php`

**Interfaces:**
- Produces: планировщик каждый час ставит `RefreshIntegrationTokenJob` на каналы с `token_expires_at < now()+2h` и провайдером, реализующим `SupportsRefresh`. Успех — обновление токенов; `ProviderAuthException` — `refresh_needed = true`, `disabled = true` + письмо владельцу организации.

- [ ] **Step 1: Тесты**

```php
it('refreshes an expiring token', function () { /* fake → новый токен в БД, refresh_needed = false */ });

it('marks the integration as broken when refresh fails with auth error', function () {
    Notification::fake();
    // провайдер бросает ProviderAuthException
    expect($integration->fresh()->refresh_needed)->toBeTrue();
    Notification::assertSentTo($owner, IntegrationBrokenNotification::class);
});

it('skips providers that do not support refresh', function () { /* Telegram не попадает в выборку */ });
```

- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add oauth token refresh job and broken channel notifications`

---

### Task 17: Фронтенд — страница «Каналы»

**Files:**
- Create: `resources/js/pages/Channels/Index.vue`, `components/channels/{ChannelCard,ConnectProviderModal,ChannelSettingsDrawer,TimeSlotEditor}.vue`, `stores/integrations.ts`
- Test: `resources/js/stores/__tests__/integrations.spec.ts`

**Interfaces:**
- Consumes: API из Task 13.
- Produces: `useIntegrationsStore()` c `list`, `fetch()`, `connect(provider)`, `update(id, payload)`, `toggle(id)`, `remove(id)`.

- [ ] **Step 1: Vitest** — `fetch()` заполняет `list`; `remove()` оптимистично убирает элемент и откатывает при ошибке.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать store + компоненты (карточка канала: аватар, имя, группа, бейдж «требует переподключения» при `refresh_needed`). **Step 4:** → PASS.
- [ ] **Step 5: Ручная проверка:** подключить реальный Telegram-канал через UI.
- [ ] **Step 6: Commit** — `feat: add channels page with connect and settings ui`

---

# M3. Посты и календарь

### Task 18: Схема постов, состояния, PostStateMachine

**Files:**
- Create: миграции `post_groups`, `posts`, `tags`, `post_group_tag`, `comments`, `sets`, `signatures`; модели; `app/Enums/PostState.php`; `app/Services/Posts/PostStateMachine.php`
- Test: `tests/Unit/Posts/PostStateMachineTest.php`

**Interfaces:**
- Produces: `PostState {DRAFT, QUEUE, PUBLISHED, ERROR}`; разрешённые переходы: `DRAFT→QUEUE`, `QUEUE→PUBLISHED`, `QUEUE→ERROR`, `ERROR→QUEUE` (ретрай), `DRAFT→DRAFT`. Всё остальное — `InvalidStateTransition`. `PostStateMachine::transition(Post|PostGroup $m, PostState $to): void`.
- `PostGroup::posts()`, `Post::children()` (тред по `parent_post_id`, сортировка по `order`).

- [ ] **Step 1: Тест переходов**

```php
it('allows queue to published', fn () => expect(PostStateMachine::can(PostState::QUEUE, PostState::PUBLISHED))->toBeTrue());
it('forbids published to queue', function () {
    app(PostStateMachine::class)->transition($post, PostState::QUEUE); // $post PUBLISHED
})->throws(InvalidStateTransition::class);
```

- [ ] **Step 2:** → FAIL. **Step 3:** Миграции, модели, машина состояний. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add posts schema and state machine`

---

### Task 19: Валидация контента под требования сети

**Files:**
- Create: `app/Services/Posts/PostValidationService.php`, `app/Exceptions/PostValidationException.php`
- Test: `tests/Unit/Posts/PostValidationServiceTest.php`

**Interfaces:**
- Produces: `PostValidationService::validate(Integration $i, array $chain): array` → массив ошибок вида `[{post_index, code, message, limit, actual}]`; коды: `content_too_long`, `too_many_images`, `too_many_videos`, `unsupported_mime`, `file_too_large`, `threads_not_supported`, `empty_content`. Данные берутся **только** из `ProviderCapabilities` — никаких платформенных `if`.

- [ ] **Step 1: Тесты** — 301 символ для Bluesky → `content_too_long` с `limit=300`; 5 изображений для Mastodon → `too_many_images`; тред для Discord → `threads_not_supported`; пустой контент без медиа → `empty_content`; валидный пост → `[]`.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add per-provider post content validation`

---

### Task 20: CRUD постов (мультиканальность, вариации, треды, черновики)

**Files:**
- Create: `app/Http/Controllers/Api/PostController.php`, `app/Http/Requests/{StorePostRequest,UpdatePostRequest}.php`, `app/Services/Posts/PostGroupService.php`, `app/Http/Resources/{PostGroupResource,PostResource}.php`, `app/Policies/PostPolicy.php`
- Test: `tests/Feature/Posts/CreatePostTest.php`, `UpdatePostTest.php`, `DeletePostTest.php`

**Interfaces:**
- Produces:
  - `POST /api/posts` — тело:

```json
{
  "type": "schedule",                 // schedule | draft | now
  "publish_date": "2026-09-01T10:00:00Z",
  "tags": ["<tag-ulid>"],
  "posts": [
    { "integration_id": "<ulid>",
      "settings": {"channel_id": "C1"},
      "value": [
        {"content": "первый твит", "media_ids": ["<ulid>"]},
        {"content": "второй в треде", "media_ids": []}
      ] }
  ]
}
```
  → 201 `PostGroupResource`. Один `post_group`, по цепочке `posts` на каждый канал.
  - `GET /api/posts/{group}`, `PUT /api/posts/{group}` (полная замена цепочек), `DELETE /api/posts/{group}`
  - `POST /api/posts/{group}/duplicate` → новая группа в состоянии `DRAFT`
  - `PATCH /api/posts/{group}/date {publish_date}` — для drag-and-drop
  - `PostGroupService::create(array $data): PostGroup`, `::update(PostGroup, array): PostGroup`, `::duplicate(PostGroup): PostGroup`

- [ ] **Step 1: Падающие тесты**

```php
it('creates one post per integration inside a single group', function () {
    $org = Organization::factory()->create(); actingAsMember($org);
    [$a, $b] = Integration::factory()->for($org)->count(2)->create();
    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'posts' => [
            ['integration_id' => $a->id, 'value' => [['content' => 'A']]],
            ['integration_id' => $b->id, 'value' => [['content' => 'B']]],
        ],
    ])->assertCreated();

    expect(PostGroup::count())->toBe(1)->and(Post::count())->toBe(2);
});

it('stores a thread as parent and children in order', function () {
    // value из 3 элементов → 1 корень + 2 ребёнка с order 1,2 и parent_post_id корня
});

it('rejects a post that violates provider limits', function () {
    // Bluesky + 400 символов → 422, errors.posts.0.value.0.content
});

it('forbids scheduling into another organization integration', function () { /* 403 */ });

it('rejects a publish_date in the past for type=schedule', function () { /* 422 */ });
```

- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать; `StorePostRequest` вызывает `PostValidationService` и превращает ошибки в `errors` формата Laravel; всё в транзакции. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add post group crud with threads and per-channel variations`

---

### Task 21: Календарь, теги, подбор слота

**Files:**
- Create: `app/Http/Controllers/Api/{CalendarController,TagController}.php`, `app/Services/Posts/SlotFinderService.php`
- Test: `tests/Feature/Posts/CalendarTest.php`, `tests/Unit/Posts/SlotFinderServiceTest.php`

**Interfaces:**
- Produces:
  - `GET /api/calendar?from=&to=&customer=&tags[]=` → плоский список групп с вложенными постами и каналами; фильтр по тегам и «клиенту».
  - `GET /api/tags`, `POST /api/tags {name,color}`, `PUT /api/tags/{id}`, `DELETE /api/tags/{id}`
  - `GET /api/posts/find-slot?integration_id=` → `{publish_date}` — ближайший свободный тайм-слот канала (из `integration_time_slots`, в таймзоне пользователя), не занятый другой группой; если слотов нет — `now()+1h`, округлённое вверх до 5 минут.

- [ ] **Step 1: Тесты**

```php
it('returns the next free slot skipping occupied ones', function () {
    // канал со слотами пн 10:00 и пн 14:00; на пн 10:00 уже есть пост → ждём 14:00
});
it('falls back to now plus one hour when no slots are configured', function () { /* ... */ });
it('filters calendar entries by tag', function () { /* 2 группы, 1 с тегом → в ответе одна */ });
it('does not leak posts from other organizations', function () { /* ... */ });
```

- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add calendar endpoint, tags and slot finder`

---

### Task 22: Комментарии команды и публичная share-страница

**Files:**
- Create: `app/Http/Controllers/Api/{CommentController,PublicPostController}.php`, `resources/js/pages/Public/SharedPost.vue`
- Test: `tests/Feature/Posts/CommentsTest.php`, `SharedPostTest.php`

**Interfaces:**
- Produces: `GET|POST /api/posts/{group}/comments`; `POST /api/posts/{group}/share` → `{url}` (генерирует `share_token`), `DELETE /api/posts/{group}/share`; публичный `GET /api/public/posts/{token}` — без авторизации, только контент и превью, без токенов и e-mail'ов участников.

- [ ] **Step 1: Тесты** — комментарий видит только член организации; публичная страница по валидному токену → 200 и не содержит `integration.token`; отозванный токен → 404.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add post comments and public share page`

---

### Task 23: Sets и Signatures

**Files:**
- Create: `app/Http/Controllers/Api/{SetController,SignatureController}.php`
- Test: `tests/Feature/Posts/{SetsTest,SignaturesTest}.php`

**Interfaces:**
- Produces: CRUD `/api/sets` (`{name, integration_ids[]}`) и `/api/signatures` (`{content, is_default}`); при `is_default=true` у остальных подписей флаг сбрасывается; в редакторе подпись подставляется на фронте (сервер её не дописывает автоматически, чтобы не ломать лимиты валидации незаметно для пользователя).

- [ ] **Step 1: Тесты** — только одна дефолтная подпись на организацию; set с чужим `integration_id` → 422.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add channel sets and signatures`

---

### Task 24: Фронтенд — календарь с drag-and-drop

**Files:**
- Create: `resources/js/pages/Calendar/Index.vue`, `components/calendar/{CalendarGrid,CalendarEvent,ViewSwitcher,TagFilter}.vue`, `stores/posts.ts`
- Modify: `package.json` (`@fullcalendar/vue3`, `daygrid`, `timegrid`, `interaction`)
- Test: `resources/js/stores/__tests__/posts.spec.ts`

**Interfaces:**
- Produces: `usePostsStore()` — `entries`, `fetchRange(from,to)`, `moveTo(groupId, date)` (оптимистично + откат при ошибке), `remove(groupId)`.
- Виды: месяц / неделя / день; drag-and-drop зовёт `PATCH /api/posts/{group}/date`; события `PUBLISHED` — не перетаскиваются (заблокированы).

- [ ] **Step 1: Vitest** — `moveTo()` меняет дату локально до ответа и возвращает исходную при 422.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add calendar ui with drag and drop rescheduling`

---

### Task 25: Фронтенд — редактор поста

**Files:**
- Create: `components/editor/{PostEditorModal,ChannelPicker,ChannelTab,ThreadEditor,ContentTextarea,ValidationHints,ScheduleControls}.vue`
- Test: `resources/js/components/editor/__tests__/ThreadEditor.spec.ts`

**Interfaces:**
- Consumes: `POST /api/posts`, `GET /api/posts/find-slot`, stores из Task 17/24.
- Поведение: выбор каналов (в т.ч. через Set) → общий текст по умолчанию → вкладка на канал для вариации → «+» добавляет сообщение в тред → счётчик символов с лимитом канала → кнопки «Черновик» / «Запланировать» / «Опубликовать сейчас».

- [ ] **Step 1: Vitest** — добавление/удаление сообщения треда меняет длину модели; счётчик краснеет при превышении `maxCharacters`.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add post editor with per-channel variations and threads`

---

### Task 26: Фронтенд — превью в стиле соцсетей

**Files:**
- Create: `components/previews/{PreviewPane,TelegramPreview,MastodonPreview,BlueskyPreview,DiscordPreview,SlackPreview}.vue`, `components/previews/registry.ts`
- Test: `resources/js/components/previews/__tests__/registry.spec.ts`

**Interfaces:**
- Produces: `previewRegistry: Record<string, Component>` + `resolvePreview(identifier): Component` с дефолтом `GenericPreview`. Никаких `v-if="provider === 'telegram'"` в компонентах-родителях — только резолв через реестр (фронтовый аналог правила из Global Constraints).

- [ ] **Step 1: Vitest** — `resolvePreview('telegram')` возвращает `TelegramPreview`; неизвестный → `GenericPreview`.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add social network post previews`

---

# M4. Медиа

### Task 27: Загрузка файлов и медиа-библиотека (API)

**Files:**
- Create: миграции `media`, `media_post`; `app/Models/Media.php`, `app/Services/Media/MediaUploadService.php`, `app/Http/Controllers/Api/MediaController.php`, `app/Http/Requests/UploadMediaRequest.php`, `app/Policies/MediaPolicy.php`
- Modify: `config/filesystems.php` (диск `media` на S3/MinIO)
- Test: `tests/Feature/Media/UploadMediaTest.php`, `MediaLibraryTest.php`

**Interfaces:**
- Produces:
  - `POST /api/media` (multipart `file`) → `MediaResource`
  - `POST /api/media/from-url {url}` → скачивает (лимит 100 МБ, таймаут 30 с, только `http/https`, запрет приватных IP — SSRF-защита)
  - `GET /api/media?page=&search=` → пагинация по 24
  - `DELETE /api/media/{id}` — 409, если медиа используется в непубликованном посте
  - `MediaUploadService::store(UploadedFile $f, Organization $o): Media` — путь `org/{org_id}/{ulid}.{ext}`, генерирует thumbnail 400px для изображений.
- Разрешённые типы: `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `video/mp4`. Проверка по реальному mime (`finfo`), не по расширению.

- [ ] **Step 1: Тесты**

```php
it('stores an uploaded image and returns its url', function () {
    Storage::fake('media');
    $org = Organization::factory()->create(); actingAsMember($org);
    $this->postJson('/api/media', ['file' => UploadedFile::fake()->image('a.jpg', 800, 600)])
        ->assertCreated()->assertJsonPath('data.mime', 'image/jpeg');
    expect(Media::count())->toBe(1);
});

it('rejects a disallowed mime type', function () {
    $this->postJson('/api/media', ['file' => UploadedFile::fake()->create('a.exe', 10)])
        ->assertStatus(422);
});

it('refuses to download from a private ip', function () {
    $this->postJson('/api/media/from-url', ['url' => 'http://169.254.169.254/latest/meta-data'])
        ->assertStatus(422);
});

it('does not list media from another organization', function () { /* ... */ });
```

- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add media upload, library and url import`

---

### Task 28: Привязка медиа к постам

**Files:**
- Modify: `app/Services/Posts/PostGroupService.php`, `app/Http/Resources/PostResource.php`, `app/Services/Posts/PostValidationService.php`
- Test: `tests/Feature/Posts/PostMediaTest.php`

**Interfaces:**
- Produces: `media_ids` в теле `POST/PUT /api/posts` сохраняются в `media_post` с сохранением порядка; в `PostResource` медиа отдаются как `[{id,url,thumbnail_url,mime,width,height}]`. Валидация: `media_ids` из другой организации → 422; количество и mime сверяются с `ProviderCapabilities`.

- [ ] **Step 1: Тесты** — порядок медиа сохраняется; чужое медиа → 422; 5 картинок для Mastodon (лимит 4) → 422.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: attach media to posts with ordering and validation`

---

### Task 29: Фронтенд — медиа-библиотека и вставка в пост

**Files:**
- Create: `resources/js/pages/Media/Index.vue`, `components/media/{MediaPicker,MediaUploader,MediaGrid,MediaTile}.vue`, `stores/media.ts`
- Test: `resources/js/stores/__tests__/media.spec.ts`

**Interfaces:**
- Produces: `useMediaStore()` — `items`, `page`, `hasMore`, `fetch()`, `upload(file, onProgress)`, `remove(id)`. `MediaPicker` открывается из редактора и возвращает выбранные `media_ids`.

- [ ] **Step 1: Vitest** — `upload()` добавляет элемент в начало списка; ошибка загрузки не оставляет «фантомный» элемент.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать (drag-and-drop зона, прогресс, превью). **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add media library ui and picker`

---

# M5. Публикация (фоновые процессы)

### Task 30: PublishPostJob — ядро публикации

**Files:**
- Create: `app/Jobs/PublishPostJob.php`, `app/Services/Posts/PublishService.php`
- Modify: `app/Services/Posts/PostGroupService.php` (постановка джобы), `config/horizon.php`
- Test: `tests/Feature/Publishing/PublishPostJobTest.php`

**Interfaces:**
- Consumes: `ProviderRegistry`, `PostStateMachine`, `PostValidationService`.
- Produces: `PublishPostJob::dispatch(string $postGroupId)` с `->delay($publishDate)`.
- Контракт джобы (важно для идемпотентности — воспроизводить дословно):

```php
final class PublishPostJob implements ShouldQueue, ShouldBeUnique
{
    public int $tries = 4;
    public array $backoff = [60, 300, 900];      // 1м, 5м, 15м
    public int $uniqueFor = 3600;

    public function __construct(public readonly string $postGroupId) {}

    public function uniqueId(): string { return $this->postGroupId; }

    public function handle(PublishService $service): void
    {
        $service->publishGroup($this->postGroupId);
    }
}
```

- `PublishService::publishGroup(string $id)`:
  1. Берёт группу `lockForUpdate()` внутри транзакции; если состояние не `QUEUE` — **выходит без действий** (защита от двойной публикации).
  2. Для каждого канала публикует всю цепочку одним вызовом `provider->post($integration, $chain)`, который
     возвращает `array<int, PostResultDto>` — по результату на сегмент цепочки.
  3. Успех → для **каждого** сегмента свои `posts.state = PUBLISHED`, `release_id`, `release_url`, `published_at`
     (в схеме на каждый элемент цепочки своя строка `posts`).
  4. Уже опубликованные посты внутри группы при ретрае **пропускаются** — как на уровне канала, так и на уровне
     сегмента цепочки: сегмент с непустым `release_id` не отправляется повторно никогда.
  5. `ProviderPartialFailureException` → сохранить `release_id` опубликованных сегментов из исключения,
     остальные сегменты оставить неопубликованными. Ретрай продолжает с первого сегмента без `release_id`
     и **обязан** проставить ему `PostPayloadDto::$replyToReleaseId` = `release_id` последнего успешного
     сегмента. Без якоря провайдер опубликует продолжение новым корнем и продублирует тред в соцсети.
  6. `ProviderTransientException` / `ProviderRateLimitException` → `release()` c backoff (для rate-limit — `retryAfterSeconds`).
  7. `ProviderPermanentException` → пост в `ERROR` с текстом, остальные каналы продолжают публиковаться.
  8. `ProviderAuthException` → канал `refresh_needed=true`, пост в `ERROR`.
  9. Группа получает `PUBLISHED`, если все её посты `PUBLISHED`, иначе `ERROR`.

- [ ] **Step 1: Падающие тесты**

```php
it('publishes every channel in the group and marks it published', function () {
    // 2 канала, фейковый провайдер → оба поста PUBLISHED, группа PUBLISHED
});

it('is idempotent: a second run does not publish twice', function () {
    $group = /* PUBLISHED группа */;
    app(PublishService::class)->publishGroup($group->id);
    expect(FakeProvider::$calls)->toBe(0);
});

it('skips already published posts on retry', function () {
    // канал A — PUBLISHED, канал B — QUEUE → провайдер вызван только для B
});

it('marks only the failing channel as errored', function () {
    // A ок, B бросает ProviderPermanentException → A PUBLISHED, B ERROR, группа ERROR
});

it('releases the job with backoff on a transient error', function () {
    Queue::fake();
    // ProviderTransientException → job released, состояние осталось QUEUE
});

it('flags the integration for reconnect on auth error', function () {
    // ProviderAuthException → integration.refresh_needed = true
});
```

- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Ручная проверка** — запланировать пост в реальный Telegram на +2 минуты, дождаться публикации, увидеть её в Horizon.
- [ ] **Step 6: Commit** — `feat: add idempotent post publishing job with retries`

---

### Task 31: Перепланирование, отмена и ретрай вручную

**Files:**
- Modify: `app/Services/Posts/PostGroupService.php`, `app/Http/Controllers/Api/PostController.php`
- Test: `tests/Feature/Publishing/RescheduleTest.php`

**Interfaces:**
- Produces: изменение `publish_date` отменяет старую джобу и ставит новую; удаление группы снимает джобу; `POST /api/posts/{group}/retry` переводит `ERROR → QUEUE` и ставит немедленную джобу (только для групп в `ERROR`).
- Отмена реализуется не удалением из Redis, а проверкой в п.1 `publishGroup` (`state !== QUEUE` → выход) + версионным полем `post_groups.queue_version`, которое сравнивается в джобе; расхождение → джоба выходит молча.

- [ ] **Step 1: Тесты** — перенос даты: старая джоба, отработав, не публикует; `retry` из `PUBLISHED` → 422; `retry` из `ERROR` → 200 и джоба поставлена.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: support rescheduling, cancelling and retrying publications`

---

### Task 32: ScanMissedPostsJob и отчёт по ошибкам

**Files:**
- Create: `app/Jobs/ScanMissedPostsJob.php`, `app/Notifications/PostFailedNotification.php`, `app/Http/Controllers/Api/PostErrorController.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Publishing/MissedPostsTest.php`

**Interfaces:**
- Produces: каждые 10 минут ищет группы в `QUEUE` с `publish_date < now()-15min` → переставляет джобу и пишет warning в лог; при исчерпании `tries` — письмо автору + запись `post_groups.error`. `GET /api/posts/errors?from=&to=` — список упавших публикаций для UI-баннера.

- [ ] **Step 1: Тесты** — просроченная группа переставляется; свежая — нет; уведомление отправляется один раз (флаг `notified_at`).
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add missed posts scanner and failure notifications`

---

### Task 33: Почтовые джобы и дайджест

**Files:**
- Create: `app/Jobs/SendDigestEmailJob.php`, `app/Mail/WeeklyDigestMail.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Mail/DigestTest.php`

**Interfaces:**
- Produces: еженедельный дайджест (пн 09:00 в таймзоне пользователя) — что опубликовано, что упало, что запланировано на неделю; уважает `users.email_notifications=false`.

- [ ] **Step 1: Тесты** — с выключенными уведомлениями письмо не уходит; в дайджесте только данные организаций пользователя.
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add weekly digest email`

---

### Task 34: Horizon, очереди, наблюдаемость

**Files:**
- Modify: `config/horizon.php`, `config/queue.php`, `app/Providers/HorizonServiceProvider.php`
- Create: `tests/Feature/HorizonAccessTest.php`

**Interfaces:**
- Produces: очереди `publish` (приоритет, 10 воркеров), `default`, `mail` (2 воркера); `Horizon::auth` — только `is_superadmin`; `failed_jobs` включены; теги джоб (`organization:{id}`, `group:{id}`) для поиска в UI.

- [ ] **Step 1: Тест доступа** — обычный пользователь на `/horizon` → 403, суперадмин → 200.
- [ ] **Step 2:** → FAIL. **Step 3:** Настроить. **Step 4:** → PASS.
- [ ] **Step 5: Ручная проверка** — упавшая джоба видна в Horizon с тегами и ретраится кнопкой.
- [ ] **Step 6: Commit** — `chore: configure horizon queues, tags and access control`

---

# M6. Лимиты тарифов (без приёма оплаты)

> Платежи в эту итерацию не входят. Тариф — колонка `organizations.plan`, меняется сидером, тинкером или админ-командой. Задача вехи — чтобы лимиты **с самого начала** проверялись в одном месте: когда оплату добавят, менять придётся только `PlanRegistry::forOrganization()`.

### Task 35: Реестр тарифов и PlanLimitService

**Files:**
- Create: `config/plans.php`, `app/Services/Billing/{PlanRegistry,PlanLimitService}.php`, `app/Enums/{Plan,PlanLimit}.php`, миграции `plan_usages` и `organizations.plan`
- Create: `app/Console/Commands/SetOrganizationPlanCommand.php`
- Test: `tests/Unit/Billing/PlanLimitServiceTest.php`

**Interfaces:**
- Produces:

```php
// config/plans.php  — источник истины по лимитам; цен здесь пока нет
return [
  'free'     => ['name' => 'Free',
                 'limits' => ['channels' => 2,  'posts_per_month' => 30,   'media_storage_mb' => 200]],
  'standard' => ['name' => 'Standard',
                 'limits' => ['channels' => 10, 'posts_per_month' => 500,  'media_storage_mb' => 5000]],
  'pro'      => ['name' => 'Pro',
                 'limits' => ['channels' => 50, 'posts_per_month' => null, 'media_storage_mb' => 50000]], // null = безлимит
];
```

- `PlanRegistry::forOrganization(Organization $o): array` — читает `organizations.plan`, неизвестное/пустое значение → `free`. **Это единственная точка, которую заменит будущий биллинг.**
- `php artisan org:plan {organization} {plan}` — ручная смена тарифа.
- `PlanLimitService::check(Organization $o, PlanLimit $limit, int $delta = 1): void` → бросает `PlanLimitExceededException` (HTTP 402, тело `{message, limit, current, max}`).
- `PlanLimitService::usage(Organization $o): array` — текущие значения по всем лимитам (для UI).
- Счётчик постов ведётся в `plan_usages` инкрементом при создании группы, период — календарный месяц UTC.

- [ ] **Step 1: Сначала тесты**

```php
it('falls back to the free plan for an organization without a plan', function () {
    $org = Organization::factory()->create(['plan' => null]);
    expect(app(PlanRegistry::class)->forOrganization($org)['name'])->toBe('Free');
});

it('allows creation within the plan limit', function () {
    $org = Organization::factory()->create(['plan' => 'free']); // 2 канала
    Integration::factory()->for($org)->create();
    app(PlanLimitService::class)->check($org, PlanLimit::CHANNELS); // не бросает
})->throwsNoExceptions();

it('throws when the channel limit is reached', function () {
    $org = Organization::factory()->create(['plan' => 'free']);
    Integration::factory()->for($org)->count(2)->create();
    app(PlanLimitService::class)->check($org, PlanLimit::CHANNELS);
})->throws(PlanLimitExceededException::class);

it('treats a null limit as unlimited', function () { /* pro + 10000 постов → не бросает */ });
it('ignores soft-deleted integrations in the channel count', function () { /* ... */ });
it('resets the monthly post counter in a new period', function () { /* ... */ });
it('changes the plan through the console command', function () {
    $org = Organization::factory()->create(['plan' => 'free']);
    $this->artisan("org:plan {$org->id} pro")->assertSuccessful();
    expect($org->fresh()->plan)->toBe(Plan::PRO);
});
```

- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS.
- [ ] **Step 5: Commit** — `feat: add plan registry and centralized limit service`

---

### Task 36: Enforcement лимитов через middleware

**Files:**
- Create: `app/Http/Middleware/EnforcePlanLimit.php`, `app/Exceptions/PlanLimitExceededException.php`
- Modify: `routes/api.php`, `bootstrap/app.php`
- Create: `resources/js/components/ui/LimitToast.vue`
- Modify: `resources/js/api/client.ts` (перехват 402)
- Test: `tests/Feature/Billing/LimitEnforcementTest.php`, `resources/js/api/__tests__/client.spec.ts`

**Interfaces:**
- Produces: `->middleware('plan:channels')` на подключение канала, `plan:posts` на создание группы, `plan:media_storage` на загрузку. Middleware зовёт `PlanLimitService::check` и отдаёт 402. **В контроллерах проверок лимитов нет** — это правило ревью. На фронте 402 перехватывается интерцептором и показывается тостом с текстом из ответа (цвет `--warning` по `color.md` §1 — это «лимит на исходе», не ошибка публикации).

- [ ] **Step 1: Сначала тесты** — на free-тарифе третий канал → 402 с телом `{limit:'channels', current:2, max:2}`; 31-й пост в месяце → 402; после `org:plan {id} standard` тот же запрос → 201. Vitest: 402 вызывает `showLimitToast` и не всплывает как «неизвестная ошибка».
- [ ] **Step 2:** → FAIL. **Step 3:** Реализовать. **Step 4:** → PASS (`pest` + `vitest`).
- [ ] **Step 5: Grep-проверка**

Run: `grep -rn "PlanLimitService" app/Http/Controllers || true`
Expected: пусто.

- [ ] **Step 6: Commit** — `feat: enforce plan limits via middleware`

---

> **Что сюда сознательно НЕ входит:** Cashier, Stripe Checkout, billing portal, вебхуки платежей, промокоды, trial с оплатой, страница тарифов и история счетов. Когда оплата понадобится — это отдельная веха поверх готового `PlanRegistry`.

---

# M7. Приёмка MVP

### Task 37: Сквозной сценарий, сидеры, документация

**Files:**
- Create: `tests/Feature/EndToEnd/MvpFlowTest.php`, `database/seeders/DemoSeeder.php`, `README.md`, `docs/DEPLOYMENT.md`
- Test: он же

**Interfaces:**
- Consumes: всё выше.
- Produces: `php artisan db:seed --class=DemoSeeder` — демо-организация, 3 канала (fake-провайдер), 10 постов в разных состояниях, 5 медиа.

- [ ] **Step 1: Написать сквозной тест**

```php
it('covers the full mvp flow end to end', function () {
    Storage::fake('media');
    Queue::fake();

    // 1. регистрация
    $this->postJson('/api/auth/register', ['name'=>'Ann','email'=>'ann@e.com','password'=>'secret1234'])
        ->assertCreated();
    $org = User::firstWhere('email','ann@e.com')->organizations->first();
    $this->withHeader('X-Organization', $org->id);

    // 2. подключение канала
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    // 3. загрузка медиа
    $media = $this->postJson('/api/media', ['file' => UploadedFile::fake()->image('a.jpg')])
        ->assertCreated()->json('data.id');

    // 4. создание поста
    $group = $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->addHour()->toIso8601String(),
        'posts' => [['integration_id' => $integration->id,
                     'value' => [['content' => 'hello', 'media_ids' => [$media]]]]],
    ])->assertCreated()->json('data.id');

    // 5. джоба поставлена с задержкой
    Queue::assertPushed(PublishPostJob::class, fn ($job) => $job->postGroupId === $group);

    // 6. пост виден в календаре
    $this->getJson('/api/calendar?from='.now()->toDateString().'&to='.now()->addWeek()->toDateString())
        ->assertOk()->assertJsonCount(1, 'data');

    // 7. публикация
    app(PublishService::class)->publishGroup($group);
    expect(PostGroup::find($group)->state)->toBe(PostState::PUBLISHED);

    // 8. лимит тарифа считается
    expect(app(PlanLimitService::class)->usage($org)['channels'])->toBe(1);
});
```

- [ ] **Step 2: Запустить** → FAIL, если где-то есть разрыв. **Step 3:** Починить найденное. **Step 4:** → PASS.
- [ ] **Step 5: Полный прогон**

Run: `./vendor/bin/pest && ./vendor/bin/phpstan analyse && ./vendor/bin/pint --test && npx vitest run && npm run build`
Expected: всё зелёное.

- [ ] **Step 6: README** — запуск за 3 команды (`cp .env.example .env`, `make up`, `make setup`), список переменных окружения, как получить креды для 5 провайдеров, как прогонять тесты.
- [ ] **Step 7: Ручная приёмка по чек-листу** (ниже).
- [ ] **Step 8: Commit** — `test: add end-to-end mvp flow and demo seeder`

---

## Чек-лист приёмки MVP

- [ ] Регистрация → письмо → верификация → вход работает на чистом окружении.
- [ ] Приглашение коллеги по ссылке; роль `USER` не может подключать и удалять каналы.
- [ ] Подключены и реально публикуют: Telegram, Mastodon, Bluesky, Discord, Slack.
- [ ] Пост в 3 канала с per-channel вариациями и тредом уходит в назначенное время.
- [ ] Перенос поста мышью в календаре меняет время публикации.
- [ ] Медиа грузится, вставляется в пост и уходит в соцсеть.
- [ ] Отключенный канал (протухший токен) помечен «требует переподключения», приходит письмо.
- [ ] Упавшая публикация видна в списке ошибок и ретраится кнопкой.
- [ ] Двойной запуск джобы не публикует пост дважды (проверено вручную через Horizon retry).
- [ ] Лимит free-тарифа блокирует 3-й канал; `php artisan org:plan {id} standard` тут же его разблокирует.
- [ ] Интерфейс проверен в обеих темах: hex-значений в компонентах нет (спека `palette.spec.ts` зелёная), три уровня фона различимы, вторичный текст читается, статусы не переданы одним лишь цветом.
- [ ] Ни одного упоминания Stripe/Cashier в кодовой базе: `grep -rniE "stripe|cashier" app config resources` → пусто.

---

## Риски и как они закрываются в этом плане

| Риск (из `LARAVEL_REWRITE_PLAN.md` B4) | Митигация |
|---|---|
| Провайдеры соцсетей — самая трудоёмкая часть | MVP ограничен пятью провайдерами без review-процесса у платформ (Telegram, Mastodon, Bluesky, Discord, Slack); контракт зафиксирован в Task 11 до написания первого провайдера |
| Замена Temporal на Queue — двойная публикация | Task 30: `ShouldBeUnique` + `lockForUpdate` + выход при `state !== QUEUE` + пропуск уже опубликованных постов + `queue_version` (Task 31); шесть тестов на идемпотентность |
| Хранение токенов | Task 12: `encrypted` cast, `$hidden`, тест «сырое значение в БД не содержит секрет» и тест «API не отдаёт токен» |
| Лимиты тарифов размазаны по контроллерам | Task 36: только middleware + grep-проверка, что `PlanLimitService` не упоминается в контроллерах |
| Генерика провайдеров | Task 11 Step 5: архитектурный тест grep'ом по `app/`, падающий на любом упоминании имени провайдера вне `app/Social/Providers`; фронтовый аналог — реестр превью в Task 26 |
| Расползание цвета по компонентам | Task 3: hex только в `app.css`, две спеки-сторожа (`palette.spec.ts`) падают на любом hex и на любом классе дефолтной палитры Tailwind в `.vue` |
| Оплату отложили — придётся переписывать лимиты | Лимиты изолированы от источника тарифа: биллинг затронет ровно один метод `PlanRegistry::forOrganization()` |

## Явно вне рамок этой итерации

**Платежи целиком:** Cashier, Stripe, checkout, портал, вебхуки, промокоды, платный trial, страница тарифов, история счетов. Тариф задаётся вручную командой `org:plan`.

**Функциональность после MVP:** AI-функции и кредиты, аналитика, короткие ссылки и UTM, AutoPost/RSS, Plugs, Webhooks, third-party коннекторы, Public API v1 и OAuth-сервер, админка суперадмина, Chrome-расширение и SDK, реалтайм-уведомления через Reverb, вторая и третья волны провайдеров. Всё это — этапы 6–9, 11–14 исходного документа.
