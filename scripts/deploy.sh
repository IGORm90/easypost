#!/usr/bin/env bash
# Roll the production stack to one release:   scripts/deploy.sh <sha>
#
# The order below is the point of the file; DeployScriptsTest pins it. In short:
# fetch (an absent image aborts before anything is touched), take a restore point,
# quiesce, move the schema, switch, prove it is up.
#
# Migrations MUST be expand-only (add tables and columns, never drop or rename):
# the old php is still answering while they run, and a rollback puts the old code
# in front of the new schema.
set -euo pipefail

TAG="${1:?usage: deploy.sh <sha>}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$SCRIPT_DIR/lib.sh"
cd "$EASYPOST_DIR"

# APP_HOST (and the rest of .env) for the smoke check.
set -a
. "$EASYPOST_ENV_FILE"
set +a

PREV="$(cat "$EASYPOST_DIR/.current-tag" 2>/dev/null || echo none)"
export EASYPOST_TAG="$TAG"
echo "deploying $TAG (previous: $PREV)"

# Tags are immutable (a commit SHA), so an image already on the host is the right
# one and `--policy missing` is correct, not merely cheap. If the image is not in
# the registry this fails HERE, with nothing stopped yet.
dc pull --policy missing

# A restore point before the schema moves.
"$SCRIPT_DIR/backup.sh"

# Maintenance window: the OLD php answers 503 until the container is replaced
# below, which lifts it. `|| true` because on a first install there is no php yet.
dc exec -T php php artisan down --retry=60 || true

# Scheduler first, so nothing new is dispatched while horizon drains. Then horizon:
# a plain `stop` waits up to stop_grace_period (180 s), so a publish in flight
# finishes. A shorter timeout or a kill is how a post gets published twice.
dc stop scheduler
dc stop horizon

# Expand-only migrations, in a throwaway container of the NEW image.
dc run --rm php php artisan migrate --force

# From here the server runs the new release, so record it now rather than after
# the smoke check: if the check fails, `dc` in a fresh shell must still point at
# what is actually running. The previous tag is kept for rollback.
echo "$PREV" > "$EASYPOST_DIR/.previous-tag"
echo "$TAG" > "$EASYPOST_DIR/.current-tag"

# Only services whose image or configuration changed are recreated.
dc up -d

# The proxy config is a bind mount that `up` does not notice changing.
dc exec -T caddy caddy reload --config /etc/caddy/Caddyfile

# nginx resolves `php` once, when it starts. A recreated php gets a new address
# and nginx would keep sending to the dead one: 502 on every request
# (docs/DEPLOYMENT.md, troubleshooting 1). Always restart it after php.
dc restart nginx

# Smoke: through the address users use. EASYPOST_CURL_OPTS is empty in production;
# a rehearsal sets it to fake DNS and trust its local CA.
ok=0
for _ in $(seq 1 30); do
    # shellcheck disable=SC2086
    if curl -fsS ${EASYPOST_CURL_OPTS:-} "https://${APP_HOST}/up" > /dev/null; then
        ok=1
        break
    fi
    sleep 2
done

if [ "$ok" != 1 ]; then
    echo "smoke check failed. Roll back: workflow_dispatch with $PREV, or scripts/rollback.sh $PREV" >&2
    exit 1
fi

dc exec -T horizon php artisan horizon:status
echo "deployed $TAG"
