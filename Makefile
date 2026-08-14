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
#
# Task 37 fix round 1 (Critical 1): on a fresh checkout vendor/ doesn't
# exist yet when `make up` first starts horizon and scheduler (both
# `php artisan ...`), so both crash on the missing vendor/autoload.php
# and stay dead -- horizon exits outright, scheduler's retry loop keeps
# the container "Up" but every schedule:run inside it still fails, so
# nothing scheduled ever runs. Neither service declares a `restart:`
# policy, and installing vendor/ afterwards does not revive an
# already-crashed container by itself.
#
# `up -d horizon scheduler` below only starts a container that isn't
# currently running -- it does NOT restart (recreate) one that already
# is. That's enough for horizon, which stays Exited until this line
# starts it. It is NOT what fixes scheduler: scheduler's container was
# never stopped (its `while true; php artisan schedule:run; sleep 60;
# done` loop, docker/compose.yaml, keeps it "Up" through every failed
# iteration), so `up -d scheduler` here is a harmless no-op on it --
# scheduler instead self-heals on its own the next time its loop calls
# `schedule:run`, which is within 60s of vendor/ existing, no `make
# setup` help required. Confirmed by reproduction: stopping vendor/,
# restarting both containers, then running the pre-fix version of this
# target (no final line) left horizon Exited(255) indefinitely and
# scheduler silently failing every cycle for as long as vendor/ stayed
# missing; with the fix, horizon comes back via this line and scheduler
# comes back on its own within one more 60s cycle.
setup: ensure-test-db
	$(COMPOSE) exec php composer install
	$(COMPOSE) exec php sh -c 'grep -q "^APP_KEY=.\+" .env || php artisan key:generate'
	$(COMPOSE) exec php php artisan migrate
	$(COMPOSE) up -d horizon scheduler

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
#
# Task 37 fix round 1 (Minor 12): this is the first thing `make setup`
# does, and `make setup` is the documented first-run command right after
# a cold `make up`. Postgres's own init (creating the "app" superuser
# database, running init-test-db.sql) can still be in progress at that
# point, so `psql` below would otherwise race it and abort this whole
# target with a connection error. `pg_isready` is polled first, up to 30
# times at 1s apart (30s bound), before ever running `psql`.
ensure-test-db:
	$(COMPOSE) exec -T postgres sh -c \
		'i=0; until pg_isready -U app -d app -q || [ $$i -ge 30 ]; do i=$$((i+1)); sleep 1; done; pg_isready -U app -d app -q'
	$(COMPOSE) exec -T postgres sh -c \
		"psql -U app -d app -tAc \"SELECT 1 FROM pg_database WHERE datname = 'app_test'\" | grep -q 1 || psql -U app -d app -c 'CREATE DATABASE app_test OWNER app'"

test: ensure-test-db
	$(COMPOSE) exec php php artisan test

migrate:
	$(COMPOSE) exec php php artisan migrate
