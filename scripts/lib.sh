#!/usr/bin/env bash
# Source this file, do not run it:   . /opt/easypost/scripts/lib.sh
#
# The ONE definition of the production `docker compose` command line. deploy.sh,
# rollback.sh, backup.sh and an operator's interactive shell all source it, so
# there is no second copy to drift.
#
# It also makes the release tag outlive a single script. Compose refuses to run
# without EASYPOST_TAG (`${EASYPOST_TAG:?...}` in docker/compose.production.yaml),
# so a shell that had not exported it could not even run `dc ps`. The tag is
# read from .current-tag, which deploy.sh writes, unless the caller already set
# one: deploy.sh does exactly that to switch release.
#
# Overridable, which is how a rehearsal runs the same commands on its own project:
#   EASYPOST_DIR       where docker/, scripts/, .env and .current-tag live
#   EASYPOST_PROJECT   Compose project name (volumes and containers are named after it)
#   EASYPOST_ENV_FILE  the env file, both for interpolation and as env_file's source
EASYPOST_DIR="${EASYPOST_DIR:-/opt/easypost}"
EASYPOST_PROJECT="${EASYPOST_PROJECT:-easypost}"
EASYPOST_ENV_FILE="${EASYPOST_ENV_FILE:-$EASYPOST_DIR/.env}"

if [ -z "${EASYPOST_TAG:-}" ] && [ -r "$EASYPOST_DIR/.current-tag" ]; then
    EASYPOST_TAG="$(cat "$EASYPOST_DIR/.current-tag")"
fi
# Exported, not just set: compose reads EASYPOST_ENV_FILE itself, for the app
# containers' env_file (docker/compose.production.yaml), not only through --env-file.
export EASYPOST_TAG EASYPOST_ENV_FILE

dc() {
    docker compose -p "$EASYPOST_PROJECT" \
        -f "$EASYPOST_DIR/docker/compose.production.yaml" \
        --env-file "$EASYPOST_ENV_FILE" "$@"
}
