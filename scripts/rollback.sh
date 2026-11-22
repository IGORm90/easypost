#!/usr/bin/env bash
# Put the previous release back, without GitHub:   scripts/rollback.sh <previous-sha>
#
# This changes the IMAGE TAG and nothing else. The compose file and proxy config
# on disk stay those of the release being rolled back; to restore them too, run
# the release workflow (workflow_dispatch) with the previous SHA, which ships the
# matching files.
#
# The database is not touched. If the newer release added columns the older code
# does not know, that is harmless (migrations are expand-only); if it is not,
# stop here and decide by hand: restoring a database in time can put an already
# published post back to "waiting", and the next worker would publish it again.
set -euo pipefail

TAG="${1:?usage: rollback.sh <previous-sha>}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$SCRIPT_DIR/lib.sh"
cd "$EASYPOST_DIR"

if [ "$TAG" = none ]; then
    echo "no previous release is recorded: nothing to roll back to" >&2
    exit 1
fi

export EASYPOST_TAG="$TAG"
echo "rolling back to $TAG"

# Same drain as a deploy: scheduler first, then a plain stop of horizon.
dc stop scheduler
dc stop horizon

cp "$EASYPOST_DIR/.current-tag" "$EASYPOST_DIR/.previous-tag" 2>/dev/null || true
echo "$TAG" > "$EASYPOST_DIR/.current-tag"

dc up -d

# php was recreated: nginx must be restarted after it (see deploy.sh).
dc restart nginx

echo "rolled back to $TAG"
