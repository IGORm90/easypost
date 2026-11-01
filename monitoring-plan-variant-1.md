# EasyPost: план мониторинга, вариант 1

01.11.2026 · Igor

## Цель и оценка

За 4–5 дней дать админу платформы три дашборда внутри EasyPost — `/horizon`, `/pulse`, `/log-viewer` — и внешний сигнал «сайт лёг». Новой инфраструктуры нет, добавляется один контейнер `pulse` (~50–100 МБ RAM).

Готово, когда по дашбордам можно за минуту ответить на пять вопросов:

1. Публикуются ли посты вовремя и сколько ждёт очередь `publish`?
2. Какие публикации упали за сутки, у какого провайдера и почему?
3. Какие эндпоинты и SQL-запросы медленные?
4. Что в логах по конкретному посту или каналу?
5. Хватает ли серверу CPU, RAM и диска?

Вне плана: график RPS и p95, метрики Postgres/Redis, алерты по метрикам. Это варианты 2–3.

## Шаг 1. Horizon: очереди, теги, уведомления

Публикация постов получает свой супервизор, чтобы импорт медиа или дайджест не задерживали пост на 9:00. Схема по образцу Pixelfed. **Оценка: 1 день.**

### Супервизоры в `config/horizon.php`

| Супервизор | Очереди | Что туда идёт | Процессов (prod) | `balance` | `timeout`, с | Порог ожидания, с |
| --- | --- | --- | --- | --- | --- | --- |
| `supervisor-publish` | `publish` | `PublishPostJob` | 3 | `false` | 120 | 60 |
| `supervisor-default` | `default`, `mail` | письма, обновление токенов, скан пропущенных | 1–2 | `auto` | 90 | 120 |
| `supervisor-media` | `media` | импорт по URL, обработка файлов | 1 | `false` | 300 | 300 |
| `supervisor-low` | `low` | еженедельный дайджест, очистка | 1 | `false` | 600 | 900 |

Числа процессов вынести в env (`HORIZON_PUBLISH_PROCESSES` и т. д.), `memory` 128 МБ на воркер. Итого 6–7 воркеров — в бюджете 400–700 МБ из базового стека. Порог ожидания из таблицы идёт в секцию `waits` (`'redis:publish' => 60` и т. д.).

### Код

1. Каждому джобу явно назначить очередь (`$this->onQueue('publish')` в конструкторе). Джоб без очереди попадёт в `default` — это нужно ловить тестом
2. `PublishPostJob::tags()` → `['org:{id}', 'post:{group_id}', 'provider:{provider}']`. В Horizon появится поиск по тегу и отслеживание `provider:telegram` как «мониторинга тега»
3. **Уведомления.** Horizon умеет почту и Slack из коробки (`Horizon::routeMailNotificationsTo`). Для Telegram — слушатель события `LongWaitDetected` в `app/Listeners/NotifyLongQueueWait.php`, который шлёт сообщение в служебный чат. Троттлинг — не чаще раза в 15 минут на очередь (`Cache::add`)
4. В тот же чат — сообщение при переходе поста в ошибку публикации после всех ретраев (метод `failed()` джоба), с агрегацией по провайдеру, чтобы сбой Bluesky не прислал 200 сообщений

### Планировщик и хранение

- `horizon:snapshot` каждые 5 минут — **без него вкладка Metrics в Horizon пустая**
- `queue:prune-failed --hours=168` раз в сутки
- `trim`: успешные джобы — 24 часа, упавшие и отслеживаемые — 7 дней (`10080` минут)
- `silenced`: служебные частые джобы, чтобы не засоряли список Completed

## Шаг 2. Pulse: производительность и публикации

Pulse даёт медленные запросы, SQL, джобы, вызовы API соцсетей, исключения и ресурсы сервера, а своя карточка — ошибки и задержку публикаций по провайдерам. **Оценка: 1–1,5 дня.**

### Установка

1. `composer require laravel/pulse`, опубликовать конфиг и миграции (таблицы `pulse_values`, `pulse_entries`, `pulse_aggregates` в той же Postgres)
2. Ingest-драйвер `storage` (по умолчанию): данные пишутся пачкой в конце запроса. Если в Pulse станет видно, что он сам тормозит запросы, — перейти на `redis` + демон `pulse:work`
3. `PULSE_SERVER_NAME=easypost-prod`, хранение — 7 дней (значение по умолчанию)
4. Pulse притягивает Livewire для своего дашборда. На Vue SPA это не влияет: `/pulse` — отдельная blade-страница вне SPA. Нужно исключить `/pulse` и `/horizon` из catch-all маршрута SPA

### Рекордеры

| Рекордер | Порог | `sample_rate` | Заметки |
| --- | --- | --- | --- |
| `SlowRequests` | 500 мс | 1 | игнорировать `/pulse`, `/horizon`, `/up`, `/log-viewer` |
| `SlowQueries` | 300 мс | 1 | `location` включён — видно файл и строку |
| `SlowJobs` | 5 000 мс | 1 | |
| `SlowOutgoingRequests` | 2 000 мс | 1 | `groups`: схлопнуть URL до домена — иначе каждый Mastodon-инстанс и ID сообщения даст отдельную строку |
| `Exceptions` | — | 1 | `location` включён |
| `Queues` | — | 1 | |
| `UserRequests`, `UserJobs` | — | 0,2 | самые активные пользователи и организации |
| `CacheInteractions` | — | 0,1 | можно выключить |
| `Servers` | — | — | собирает демон `pulse:check` (шаг 5), диски `/` и том MinIO |

### Своя карточка «Публикации»

1. В `PublishPostJob` после исхода публикации:
    - `Pulse::record('publish_ok', $provider)->count()` или `Pulse::record('publish_failed', $provider)->count()`
    - `Pulse::record('publish_delay', $provider, $delaySeconds)->avg()->max()`, где задержка = фактическое время публикации − `publish_at`
2. Livewire-компонент `app/Livewire/Pulse/Publishing.php` + шаблон: таблица по провайдерам — успешно, с ошибкой, доля ошибок, средняя и максимальная задержка за выбранный период. Данные — через `$this->aggregate()`
3. Опубликовать `resources/views/vendor/pulse/dashboard.blade.php` и собрать дашборд: серверы → Публикации → очереди → исключения → медленные внешние запросы → медленные запросы, SQL, джобы → активные пользователи
4. `Pulse::user()` — показывать имя, email и организацию вместо ID

## Шаг 3. Логи

Цель — найти всё про конкретный пост одним поиском по `post_group_id`, сквозь веб-запрос и джобы. **Оценка: 0,5 дня.**

### Каналы в `config/logging.php`

- `stack` = `daily` + `stderr`: файлы — для Log Viewer, stderr — для `docker logs` и будущего Loki/Nightwatch
- `daily` хранит 14 дней (`LOG_DAILY_DAYS=14`), уровень `info` в проде
- Отдельный канал `publishing` (файл `publishing-YYYY-MM-DD.log`): успех — `info`, ретрай — `warning`, финальная ошибка — `error` с ответом API провайдера

### Контекст

1. Middleware `app/Http/Middleware/AddLogContext.php`: `Context::add()` с `request_id`, `user_id`, `organization_id`. Фасад `Context` сам передаёт эти данные в джобы, поставленные из запроса, и дописывает их в каждую запись лога
2. `request_id` отдавать в заголовке ответа `X-Request-Id` — по скриншоту ошибки от пользователя сразу находится запись
3. В `PublishPostJob::handle()` — `Context::add(['post_group_id' => …, 'integration_id' => …, 'provider' => …, 'attempt' => $this->attempts()])`
4. **Секреты.** Никогда не логировать токены каналов и заголовки `Authorization`. Ответы API пропускать через функцию, маскирующую поля `*token*`, `*secret*`, `password`

### Log Viewer

- `composer require opcodesio/log-viewer`, путь `/log-viewer`, доступ по gate из шага 4
- В Docker `storage/logs` — общий том для `app`, `horizon`, `scheduler`. Иначе логи джобов останутся внутри контейнера Horizon и Log Viewer их не увидит
- Проверить совместимость самой свежей версии пакета с Laravel 13 перед установкой

## Шаг 4. Доступ к дашбордам

Дашборды показывают данные всех организаций, поэтому доступ нужен на уровне платформы, а не по роли в организации. **Оценка: 0,25 дня.**

1. **Проверить, что такое `SUPERADMIN` в коде.** Если это роль участника организации, то владелец любой организации увидел бы чужие логи и посты. Тогда нужен отдельный признак: поле `users.is_platform_admin` или список email в `MONITORING_ADMINS`
2. Один gate `viewMonitoring` в `AppServiceProvider`, на него ссылаются все три пакета:
    - Horizon — gate `viewHorizon` в `HorizonServiceProvider`
    - Pulse — gate `viewPulse`
    - Log Viewer — `LogViewer::auth()` или gate `viewLogViewer`
3. Все три страницы живут на `web`-мидлварах. SPA уже авторизуется сессионной кукой Sanctum, поэтому отдельный вход не нужен. Проверить на staging, что сессия видна на `/horizon`
4. Пункт «Мониторинг» в меню SPA — только для админов платформы, ссылками на три страницы
5. Пока в проекте нет 2FA, можно дополнительно закрыть эти пути на прокси по IP-списку

## Шаг 5. Docker

К базовому стеку добавляется один сервис `pulse`. Всем сервисам нужны healthcheck, лимит памяти и ротация логов Docker. **Оценка: 0,5 дня.**

| Сервис | Команда | Healthcheck | `mem_limit` |
| --- | --- | --- | --- |
| `app` | веб-сервер образа | `GET /healthz` | 512m |
| `horizon` | `php artisan horizon` | `healthcheck-horizon` (есть в образах serversideup) | 768m |
| `scheduler` | `php artisan schedule:work` | `healthcheck-schedule` | 128m |
| `pulse` **(новый)** | `php artisan pulse:check` | проверка, что процесс жив (`pgrep -f pulse:check`) | 128m |
| `postgres` | — | `pg_isready` | 512m |
| `redis` | `--maxmemory 200mb --maxmemory-policy noeviction` | `redis-cli ping` | 256m |
| `minio` | — | `/minio/health/live` | 384m |

Итого лимитов ~2,7 ГБ — с запасом помещается на VPS 4 ГБ.

### Детали

- **Общий том логов** `easypost-logs:/var/www/html/storage/logs` у `app`, `horizon`, `scheduler`, `pulse` (шаг 3)
- **Redis без вытеснения.** При политике `allkeys-lru` Redis может молча выкинуть джобы из очереди. Нужна `noeviction` и AOF (`--appendonly yes`)
- **Остановка Horizon при деплое:** `stop_grace_period: 180s` — больше `timeout` очереди `publish`, чтобы публикация успела дойти до конца. Долгие джобы из `low` могут перезапуститься — они должны быть идемпотентными
- **Ротация логов Docker** через общий блок `x-logging` в `docker-compose.yml`: драйвер `json-file`, `max-size: 10m`, `max-file: 5`. Иначе stderr за месяц забьёт диск
- Все сервисы — `restart: unless-stopped`, а `depends_on` с `condition: service_healthy` на Postgres и Redis

```yaml
  pulse:
    image: easypost-app:${TAG}
    command: ["php", "artisan", "pulse:check"]
    env_file: .env
    volumes: ["easypost-logs:/var/www/html/storage/logs"]
    depends_on:
      postgres: { condition: service_healthy }
    healthcheck:
      test: ["CMD-SHELL", "pgrep -f pulse:check"]
      interval: 30s
    mem_limit: 128m
    restart: unless-stopped
    logging: *default-logging
```

## Шаг 6. Внешний пинг и health-проверки

Всё, что выше, живёт на том же сервере и молчит, когда сервер лёг. Внешний пинг закрывает эту дыру, а расширенный `/up` ловит ещё и тихо упавшие Horizon и планировщик. **Оценка: 0,5 дня.**

### Расширенный `/up`

Маршрут `/up` вызывает событие `DiagnosingHealth`. Слушатель `app/Listeners/CheckSystemHealth.php` бросает исключение — и `/up` отвечает 500, если:

1. Postgres не отвечает на `select 1`
2. Redis не отвечает на `PING`
3. Нет живого мастер-процесса Horizon (репозиторий `MasterSupervisorRepository` пуст)
4. Планировщик не отмечался дольше 3 минут: ежеминутная задача пишет в кеш `scheduler:heartbeat`, проверка смотрит на его возраст

Каждая проверка — с таймаутом около секунды, чтобы пинг раз в минуту не нагружал сервер. Ответ не должен раскрывать детали: причина уходит в лог, наружу — только 500. Docker-healthcheck `app` при этом проверяет отдельный лёгкий маршрут `/healthz`: иначе падение Horizon сделает `app` «unhealthy».

### Внешний сервис

- UptimeRobot или Better Stack на бесплатном тарифе: HTTP-проверка `https://<домен>/up`, уведомления в тот же служебный Telegram-чат
- Вторая проверка — срок действия SSL-сертификата
- Интервал и лимиты бесплатных тарифов сверить при выборе сервиса

## Тесты и приёмка

### Тесты в `tests/Feature/Monitoring/`

- `DashboardAccessTest` — админ платформы получает 200 на `/horizon`, `/pulse`, `/log-viewer`; `SUPERADMIN` организации и гость — 403
- `JobQueuesTest` — каждый класс из `app/Jobs` уходит в одну из четырёх очередей, `PublishPostJob` — в `publish`; новый джоб без очереди роняет тест
- `PublishPostJobTagsTest` — теги `org:`, `post:`, `provider:` на месте
- `PublishingPulseTest` — успех и ошибка публикации пишут `publish_ok` / `publish_failed` и `publish_delay` (`Pulse::fake()` или проверка `pulse_entries`)
- `LogContextTest` — запись из `PublishPostJob` содержит `post_group_id`, `provider`, `request_id` исходного запроса
- `LogSecretsTest` — токен канала не попадает в лог ни при успехе, ни при ошибке API
- `HealthCheckTest` — `/up` отдаёт 500 при недоступном Redis, пустом списке мастеров Horizon, устаревшем heartbeat планировщика; `/healthz` при этом 200
- `LongWaitNotificationTest` — `LongWaitDetected` шлёт одно сообщение за 15 минут, а не на каждое событие

### Приёмка на staging

- [ ] В Horizon четыре супервизора, вкладка Metrics заполняется через 10 минут
- [ ] Поиск по тегу `post:{id}` находит джоб публикации
- [ ] Остановленный контейнер `horizon` → через 1–2 минуты приходит алерт внешнего пинга и сообщение о долгом ожидании `publish`
- [ ] Публикация в канал с отозванным токеном видна в карточке «Публикации», в Telegram-чате и в Log Viewer по `post_group_id`
- [ ] Логи джобов из контейнера `horizon` видны в Log Viewer
- [ ] В Pulse видны CPU, RAM и диск сервера
- [ ] `docker stats` после суток работы: ни один контейнер не упирается в `mem_limit`

## Порядок работ

Итого 4–5 дней с тестами и приёмкой. Доступ идёт первым: без него ни один дашборд нельзя выкатывать. Каждый шаг — отдельный PR со своими тестами.

| # | Шаг | Дни | Зависит от |
| --- | --- | --- | --- |
| 1 | Доступ: админ платформы + gate `viewMonitoring` (шаг 4) | 0,25 | — |
| 2 | Horizon: супервизоры, теги, уведомления (шаг 1) | 1 | 1 |
| 3 | Логи: каналы, контекст, Log Viewer (шаг 3) | 0,5 | 1 |
| 4 | Pulse + карточка «Публикации» (шаг 2) | 1–1,5 | 1 |
| 5 | Docker: `pulse`, healthchecks, лимиты, том логов (шаг 5) | 0,5 | 3, 4 |
| 6 | Расширенный `/up` + внешний пинг (шаг 6) | 0,5 | 2, 5 |
| 7 | Приёмка на staging, сутки наблюдения, подстройка порогов | 0,5 | 1–6 |

Шаги 2, 3 и 4 независимы и могут идти параллельно. После запуска следующий шаг — вариант 2 (Nightwatch): всё из этого плана остаётся, добавляются RPS и трейсы.
