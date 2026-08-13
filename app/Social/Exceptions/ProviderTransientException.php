<?php

namespace App\Social\Exceptions;

use RuntimeException;

/**
 * A provider call failed for a reason that is expected to clear up on
 * its own: network timeout, 5xx from the remote API, connection reset,
 * etc. The publishing pipeline (Task 30) retries these with backoff, up
 * to its configured retry limit, before giving up and failing the post.
 *
 * Do NOT throw this for anything the retry can't possibly fix (bad
 * content, expired token, hit a rate limit) — those have their own,
 * more specific exception below and retrying them either wastes retries
 * or makes the problem worse.
 */
class ProviderTransientException extends RuntimeException {}
