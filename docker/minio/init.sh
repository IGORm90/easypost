#!/bin/sh
set -e

# Task 27 review, second pass, finding 1 (Important, introduced by the
# first fix): `mc anonymous set download` grants unauthenticated
# `s3:GetObject` AND `s3:ListBucket` on the whole bucket -- verified on
# the running stack: `curl "http://localhost:9000/media/?list-type=2"`
# returned a full, unauthenticated ListBucketResult, enumerating every
# object across every organization, unpublished drafts included. ULID
# paths being unguessable is irrelevant once the server hands over the
# index.
#
# media-policy.json (mounted alongside this script) grants exactly
# `s3:GetObject` on `arn:aws:s3:::media/*` -- object-level only, no
# bucket-level statement at all, so listing the bucket is refused while
# a direct, known object key still resolves. This is what a real S3
# public-read object ACL would have granted in the first place; the
# over-broad grant was specific to `mc anonymous set download`'s coarse
# shortcut, not to the underlying product decision (public GetObject on
# media is intentional -- see this repo's Task 27 fix report).
until mc alias set local "http://minio:9000" "${MINIO_ROOT_USER}" "${MINIO_ROOT_PASSWORD}"; do
    sleep 1
done

mc mb --ignore-existing local/media
mc anonymous set-json /minio-policy/media-policy.json local/media
