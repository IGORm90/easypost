<?php

namespace App\Social\Providers;

use App\Social\Contracts\ProviderErrorAdapter;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderTransientException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * The one recipe every provider HTTP call goes through. The provider builds
 * the request with `http()`/`uploadHttp()` (BoundsHttpTimeouts, so the
 * timeouts and `allow_redirects => false` are already applied) and hands it
 * over as a closure; this class never touches the `Http` facade itself.
 *
 * Fixed order, and the order is part of the interface:
 *   1. run the closure, exactly once — this class never retries;
 *   2. a ConnectionException becomes a ProviderTransientException;
 *   3. a 3xx is refused, before the adapter sees the response;
 *   4. the adapter decides whether the response failed, and builds the error;
 *   5. the body is decoded.
 */
final class ProviderCall
{
    public function __construct(
        private readonly string $network,
        private readonly ProviderErrorAdapter $adapter,
    ) {}

    /**
     * @param  Closure(): Response  $request
     * @return array<string, mixed>
     */
    public function json(Closure $request, string $context, bool $auth = false): array
    {
        $body = $this->run($request, $context, $auth)->json();

        /** @var array<string, mixed> */
        return is_array($body) ? $body : [];
    }

    /**
     * For endpoints whose body is a bare list or scalar rather than an object.
     *
     * @param  Closure(): Response  $request
     */
    public function raw(Closure $request, string $context, bool $auth = false): mixed
    {
        return $this->run($request, $context, $auth)->json();
    }

    /**
     * @param  Closure(): Response  $request
     */
    private function run(Closure $request, string $context, bool $auth): Response
    {
        $response = $this->refuseRedirect($this->send($request));

        if ($this->adapter->failed($response)) {
            throw $this->adapter->errorFor($response, $context, $auth);
        }

        return $response;
    }

    /**
     * Deliberately does NOT attach the caught ConnectionException as
     * $previous: its message can echo request details a caller supplied
     * (a bearer token appended to the URL, an app password in a request
     * body) depending on the HTTP client's error formatting. The policy is
     * blanket, not judged per provider, and this is the one place it lives.
     *
     * @param  Closure(): Response  $request
     */
    private function send(Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new ProviderTransientException("Network error while calling the {$this->network} API.");
        }
    }

    /**
     * Names the status and the network only — never the Location header or
     * the body: this message reaches `posts.error` and the API response, and
     * a remote host must not choose what lands there.
     */
    private function refuseRedirect(Response $response): Response
    {
        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            throw new ProviderPermanentException(
                "The {$this->network} API answered with an unexpected redirect ({$status}), which is never followed.",
            );
        }

        return $response;
    }
}
