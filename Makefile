COMPOSE = docker compose -f docker/compose.yaml

.PHONY: up down sh test migrate ensure-test-db setup seed

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

sh:
	$(COMPOSE) exec php sh

# Task 37: the third of the README's three setup commands (`cp
# .env.example .env`, `make up`, `make setup`). Installs PHP deps
# (vendor/ is bind-mounted from the host, not baked into the php image —
# see docker/php/Dockerfile), generates APP_KEY ONLY if one isn't already
# set (never overwrite an existing key -- see docs/DEPLOYMENT.md's
# "APP_KEY is permanent" warning: regenerating it makes every already-
# encrypted Integration token undecryptable), creates the app_test
# database, and runs migrations. Idempotent: safe to re-run on an
# already-set-up environment.
setup: ensure-test-db
	$(COMPOSE) exec php composer install
	$(COMPOSE) exec php sh -c 'grep -q "^APP_KEY=.\+" .env || php artisan key:generate'
	$(COMPOSE) exec php php artisan migrate

# Populates the demo workspace (DemoSeeder) — three connected channels,
# five uploaded media files, ten posts across every lifecycle state. Safe
# to run repeatedly; see that class's docblock.
seed:
	$(COMPOSE) exec php php artisan db:seed --class=DemoSeeder

# Idempotent, non-destructive: creates the app_test database on demand if it
# doesn't exist yet. Covers volumes created before docker/postgres/init-test-db.sql
# existed (init scripts only run against a brand-new data directory), so a
# pre-existing pgdata volume still gets app_test without anyone remembering a
# manual step or resetting the volume. CREATE DATABASE has no "IF NOT EXISTS"
# in Postgres, so existence is checked explicitly via pg_catalog.pg_database
# first; never touches the "app" database.
ensure-test-db:
	$(COMPOSE) exec -T postgres sh -c \
		"psql -U app -d app -tAc \"SELECT 1 FROM pg_database WHERE datname = 'app_test'\" | grep -q 1 || psql -U app -d app -c 'CREATE DATABASE app_test OWNER app'"

test: ensure-test-db
	$(COMPOSE) exec php php artisan test

migrate:
	$(COMPOSE) exec php php artisan migrate
