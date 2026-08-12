-- Runs automatically on first initialization of the postgres data volume
-- (postgres:*-alpine images execute every *.sql/*.sh in
-- /docker-entrypoint-initdb.d on an empty data directory only).
-- Gives the test suite its own database so RefreshDatabase never touches
-- the "app" database used for local development/demo data.
CREATE DATABASE app_test OWNER app;
