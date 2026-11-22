#!/usr/bin/env bash
# Back up the database and the media bucket to the restic repository:
#   scripts/backup.sh
#
# Run from cron (daily) and by deploy.sh before every release. Credentials for the
# repository live in restic.env next to .env, never in this file.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$SCRIPT_DIR/lib.sh"

set -a
. "${EASYPOST_RESTIC_ENV:-$EASYPOST_DIR/restic.env}"
. "$EASYPOST_ENV_FILE"
set +a

# Under the deploy user's own directory: /var/backups belongs to root.
STAGE="$EASYPOST_DIR/backup-stage"
install -d -m 700 "$STAGE" "$STAGE/media"

# 1. Database: pg_dump, never a copy of the live data directory. It goes to a
#    temporary name first: if pg_dump fails the redirect has already created an
#    empty file, which `pipefail` does not catch and restic would happily store.
#    The dump is only given its real name once it is non-empty AND readable as an
#    archive.
dc exec -T postgres pg_dump -U "$DB_USERNAME" -Fc "$DB_DATABASE" > "$STAGE/db.dump.tmp"
test -s "$STAGE/db.dump.tmp"
dc exec -T postgres pg_restore --list < "$STAGE/db.dump.tmp" > /dev/null
mv "$STAGE/db.dump.tmp" "$STAGE/db.dump"

# 2. Media: a mirror of the bucket. The network and the image are taken from the
#    running MinIO rather than guessed (the Compose project name is not fixed in
#    every setup, and minio/mc is no longer published; the MinIO image carries mc).
MINIO_ID="$(dc ps -q minio)"
NET="$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}' "$MINIO_ID")"
MC_IMAGE="$(docker inspect -f '{{.Config.Image}}' "$MINIO_ID")"
# HOME=/tmp: as a non-root --user the image leaves HOME at "/", where mc cannot save its config.
docker run --rm --network "$NET" --user "$(id -u):$(id -g)" --entrypoint mc \
    -e HOME=/tmp \
    -e MC_HOST_local="http://${MINIO_ROOT_USER}:${MINIO_ROOT_PASSWORD}@minio:9000" \
    -v "$STAGE/media:/out" "$MC_IMAGE" mirror --overwrite --remove local/media /out

# 3. Off the host, encrypted, and thinned: 7 daily and 4 weekly restore points.
restic backup "$STAGE" --tag easypost
restic forget --tag easypost --keep-daily 7 --keep-weekly 4 --prune
restic check --read-data-subset=5%

echo "backup done"
