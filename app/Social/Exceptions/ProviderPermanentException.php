<?php

namespace App\Social\Exceptions;

use RuntimeException;

/**
 * A provider call failed for a reason retrying will never fix: content
 * rejected by the network's own validation, the target no longer
 * exists, a permission was revoked at the app level, etc. The
 * publishing pipeline (Task 30) does not retry these — it fails the
 * post outright and marks it ERROR.
 *
 * If the failure is about the credential itself (expired/revoked
 * token), throw ProviderAuthException instead so the channel gets
 * flagged for reconnection rather than just failing this one post.
 *
 * If some leading segments of the chain passed to post() already
 * published successfully before this failure occurred, throw
 * ProviderPartialFailureException instead — those segments must be
 * persisted, not silently lost by failing the whole post.
 */
class ProviderPermanentException extends RuntimeException implements ProviderException {}
