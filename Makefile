COMPOSE = docker compose -f docker/compose.yaml

.PHONY: up down sh test migrate

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

sh:
	$(COMPOSE) exec php sh

test:
	$(COMPOSE) exec php php artisan test

migrate:
	$(COMPOSE) exec php php artisan migrate
