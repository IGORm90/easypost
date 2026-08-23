<?php

namespace App\Services\Integrations;

use App\Models\Organization;

/**
 * What a consumed OAuth state resolves to: the organization the connect
 * belongs to, and whatever the provider asked to have remembered when
 * the state was minted (Block 5.0.2).
 *
 * A dedicated object rather than a two-element array because both halves
 * are load-bearing and neither reads correctly as `$result[0]`: the
 * organization is the tenant every subsequent write is scoped to, and
 * the extra can carry a PKCE `code_verifier`, i.e. a secret. Naming them
 * is the cheapest way to keep a caller from quietly swapping the two.
 */
final readonly class OAuthStateResult
{
    /**
     * @param  array<string, mixed>  $extra  Values the provider returned in AuthUrlDto::$extra and this app stored server side. Empty when the provider needed nothing remembered.
     */
    public function __construct(
        public Organization $organization,
        public array $extra = [],
    ) {}
}
