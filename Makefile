COMPOSE = docker compose -f docker/compose.yaml

.PHONY: up down sh test migrate ensure-test-db

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

sh:
	$(COMPOSE) exec php sh

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
