#!/bin/sh
# Runs at the start of EVERY container built from the app image: php-fpm,
# horizon, scheduler, pulse and the one-off `run --rm php php artisan ...`.
#
# The caches are built here, not at image build, because config:cache bakes the
# environment into bootstrap/cache/config.php. Built into the image it would
# freeze the build machine's (empty) environment, and baking the real one in
# would put every credential into a layer. Built here it reads this container's
# own env_file, so changing .env and recreating the container is the whole
# procedure.
#
# `event:cache` is deliberately absent: bootstrap/app.php switches event
# discovery off (withEvents(discover: false)) because discovery registered
# listeners twice. Caching events would bring the discovery back.
set -e

cd /var/www/html

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

# The stock image entrypoint turns `-flags` into php-fpm arguments; keep it.
exec docker-php-entrypoint "$@"
