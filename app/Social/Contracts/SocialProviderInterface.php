<?php

namespace App\Social\Contracts;

use App\Models\Integration;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Dto\ProviderCapabilities;

/**
 * The one contract every social-network integration implements. This is
 * the boundary the rest of the application is written against — nothing
 * outside `app/Social/Providers/` may branch on a specific network's
 * name or behaviour (see the project's Global Constraints and
 * tests/Unit/Architecture/ProviderIsolationTest.php, which enforces it).
 *
 * Implementations are pure adapters: translate between this contract and
 * one network's HTTP API. No database access, no framework facades
 * beyond what's needed to make an HTTP call, no knowledge of
 * Organizations/Teams/Posts — those belong to the service layer that
 * calls into this interface.
 */
interface SocialProviderInterface
{
    /**
     * Stable machine identifier: a short lowercase slug naming the
     * network. Matches the key this provider is registered under in
     * config/social.php and is what ProviderRegistry::get() is called
     * with.
     */
    public function identifier(): string;

    /**
     * Human-readable display name for the network.
     */
    public function name(): string;

    public function capabilities(): ProviderCapabilities;

    /**
     * Build the URL to send the user's browser to in order to start
     * connecting an account of this network. $state is an opaque,
     * caller-generated CSRF token the provider must round-trip
     * unmodified (either via the OAuth `state` parameter or equivalent)
     * so the callback can be verified.
     */
    public function generateAuthUrl(string $state, string $redirectUri): AuthUrlDto;

    /**
     * Complete an authorization flow started by generateAuthUrl() and
     * exchange whatever the network's callback handed back ($params —
     * e.g. the callback's query string) for a usable access token.
     *
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto;

    /**
     * Publish $chain to the given Integration. $chain is a thread: the
     * first element is the root post, each subsequent element is posted
     * as a reply to the previous one. A non-threaded post is a
     * single-element $chain.
     *
     * Returns one PostResultDto per chain element, keyed by the same
     * index as $chain — never a single aggregate result — because the
     * app persists one row per chain item (each with its own
     * release_id/release_url) and needs every segment's id to do so.
     * On full success the returned array has exactly the same length
     * as $chain.
     *
     * Throws one of the App\Social\Exceptions taxonomy on failure —
     * never a raw HTTP/network exception — since the publishing
     * pipeline (Task 30) decides how to react (retry, mark the channel
     * broken, fail the post, persist-partial-and-fail-remainder) purely
     * from which exception type was thrown. In particular: if one or
     * more leading segments of $chain already published successfully
     * before a later segment failed, throw
     * ProviderPartialFailureException (carrying the results for the
     * segments that did succeed) instead of any of the other four —
     * returning early or throwing a plain failure would lose or
     * duplicate what already went out.
     *
     * @param  PostPayloadDto[]  $chain  Thread: first element is the root.
     * @return array<int, PostResultDto> Keyed by chain index; same length as $chain on full success.
     */
    public function post(Integration $integration, array $chain): array;
}
