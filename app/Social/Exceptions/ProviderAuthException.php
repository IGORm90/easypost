<?php

namespace App\Social\Exceptions;

use RuntimeException;

/**
 * The credential a provider call used is no longer valid: the access
 * token expired and (per SupportsRefresh, if implemented) couldn't be
 * refreshed, or was revoked/denied by the remote network. The
 * publishing pipeline (Task 30) does not retry these — it marks the
 * Integration/channel `refresh_needed` and surfaces to the user that
 * they must reconnect the account. The post itself still fails, but the
 * signal is "fix the connection", not "try again" or "content is bad".
 *
 * If some leading segments of the chain passed to post() already
 * published successfully before the credential failure was hit, throw
 * ProviderPartialFailureException instead — those segments must be
 * persisted, not silently lost by failing the whole post.
 */
class ProviderAuthException extends RuntimeException implements ProviderException {}
