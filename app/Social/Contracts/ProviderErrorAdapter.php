<?php

namespace App\Social\Contracts;

use App\Social\Exceptions\ProviderException;
use Illuminate\Http\Client\Response;

/**
 * The part of a provider call only the network itself can answer: did this
 * response fail, and what does that failure mean for the pipeline. Everything
 * else about a call (timeouts, redirects, transport errors, decoding) is the
 * same for every network and lives in `ProviderCall`.
 *
 * `failed()` exists separately from `errorFor()` because "failed" is not
 * always "not 2xx": Telegram answers `{"ok": false}` over a 200.
 */
interface ProviderErrorAdapter
{
    public function failed(Response $response): bool;

    /**
     * Only called after `failed()` returned true. `$auth` is true for
     * calls where any rejection means the credential itself is no good
     * (createSession/refreshSession), not that the content was invalid.
     */
    public function errorFor(Response $response, string $context, bool $auth): ProviderException;
}
