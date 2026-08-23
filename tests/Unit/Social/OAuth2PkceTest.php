<?php

use App\Social\Providers\UsesOAuth2Pkce;

/*
 * Block 5.0.3: four of the eight new networks (X, LinkedIn, Reddit,
 * Meta) start their connect flow the same way — a random verifier, its
 * SHA-256 sent as the challenge, the verifier produced again at token
 * exchange. Only the crypto lives here; the token exchange itself does
 * NOT, because the four disagree about the URL, about how the client
 * authenticates (Basic vs. form body) and about the response shape, and
 * a "universal" exchange would be a per-provider branch wearing a trait
 * as a disguise.
 */
$subject = new class
{
    use UsesOAuth2Pkce;

    public function verifier(): string
    {
        return $this->pkceVerifier();
    }

    public function challenge(string $verifier): string
    {
        return $this->pkceChallenge($verifier);
    }
};

it('produces a verifier within the length RFC 7636 allows', function () use ($subject) {
    $verifier = $subject->verifier();

    // RFC 7636 §4.1: 43..128 characters. Shorter is guessable; longer is
    // rejected outright by some authorization servers.
    expect(strlen($verifier))->toBeGreaterThanOrEqual(43);
    expect(strlen($verifier))->toBeLessThanOrEqual(128);
});

it('produces a verifier from the unreserved alphabet only', function () use ($subject) {
    // base64url, unpadded: '+', '/' and '=' would be re-encoded in the
    // token request and no longer hash to the challenge we sent.
    expect($subject->verifier())->toMatch('/^[A-Za-z0-9\-._~]+$/');
});

it('never produces the same verifier twice', function () use ($subject) {
    $seen = [];
    for ($i = 0; $i < 50; $i++) {
        $seen[] = $subject->verifier();
    }

    expect(array_unique($seen))->toHaveCount(50);
});

it('derives the challenge as the unpadded base64url of the sha-256 of the verifier', function () use ($subject) {
    // Pinned against a hand-computed value rather than against the
    // implementation restating itself: an S256 challenge that is merely
    // "some hash" fails at the provider, days later, with a message that
    // does not mention PKCE.
    $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    expect($subject->challenge($verifier))->toBe($expected);
    expect($subject->challenge($verifier))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

it('produces a challenge free of padding and of the standard-base64 characters', function () use ($subject) {
    $challenge = $subject->challenge($subject->verifier());

    expect($challenge)->toMatch('/^[A-Za-z0-9\-_]+$/');
    expect($challenge)->not->toContain('=');
});
