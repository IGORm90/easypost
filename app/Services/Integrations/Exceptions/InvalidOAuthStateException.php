<?php

namespace App\Services\Integrations\Exceptions;

use RuntimeException;

/**
 * The `state` value presented to `POST
 * /api/integrations/{provider}/callback` is missing, expired, already
 * consumed, or was minted for a different organization/user than the one
 * completing the callback. `IntegrationController` maps this to a 422 —
 * never a raw 500 — since it is routinely reachable by a replayed or
 * forged request, not just a programming error.
 */
class InvalidOAuthStateException extends RuntimeException {}
