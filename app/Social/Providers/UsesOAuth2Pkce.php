<?php

namespace App\Social\Providers;

/**
 * The PKCE half of an OAuth 2.0 authorization-code flow (RFC 7636),
 * shared by the networks in Block 5 that require it — the provider
 * generates a verifier, sends only its hash to the authorization
 * endpoint, and proves ownership of the code later by producing the
 * verifier itself.
 *
 * Deliberately ONLY the crypto. There is no token-exchange helper here,
 * although four providers are about to perform one: they disagree about
 * the endpoint, about how the client authenticates (X sends HTTP Basic,
 * Reddit sends Basic with an empty-ish secret, LinkedIn and Meta send
 * the credentials in the form body) and about the response shape. A
 * "universal" exchange would have to branch on which network it was
 * serving, which is precisely the per-provider branching this app keeps
 * out of shared code — a branch inside a trait is not less of a branch
 * for being hidden there.
 *
 * Where the verifier lives between the two calls matters as much as how
 * it is generated: the provider returns it in AuthUrlDto::$extra, and
 * IntegrationController stores that alongside the OAuth state, server
 * side. It must never travel to the browser, which is why the state
 * service — not sessionStorage — is the thing that remembers it.
 */
trait UsesOAuth2Pkce
{
    /**
     * A fresh code verifier: 43 characters of base64url, unpadded.
     *
     * 32 random bytes rather than the 96 the RFC's upper bound would
     * allow: the security of PKCE rests on the verifier being
     * unguessable, and 256 bits already is. The shorter string also
     * stays clear of the length limits some authorization servers apply
     * to a query parameter.
     *
     * `random_bytes()` and not `Str::random()`: this value is a secret,
     * and the difference between a CSPRNG and a convenience helper is
     * the entire guarantee.
     */
    protected function pkceVerifier(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    /**
     * The S256 challenge for a verifier — the only method this app
     * offers, because the alternative the RFC permits (`plain`, where
     * the challenge IS the verifier) hands the verifier to anyone who
     * can read the authorization request and defeats the point.
     */
    protected function pkceChallenge(string $verifier): string
    {
        return $this->base64Url(hash('sha256', $verifier, true));
    }

    /**
     * base64url per RFC 4648 §5, unpadded. Standard base64's `+`, `/`
     * and `=` are all re-encoded when they travel through a query
     * string, and a challenge that arrives percent-encoded no longer
     * matches the hash of the verifier presented at token exchange —
     * the flow then fails at the last step, with an error message from
     * the network that says nothing about encoding.
     */
    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
