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
 */
class ProviderAuthException extends RuntimeException {}
