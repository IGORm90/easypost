<?php

namespace App\Services\Auth\Socialite;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

/**
 * A minimal, generic OpenID Connect driver for whatever OIDC-compliant
 * identity provider a deployment points `OIDC_BASE_URL` at (Okta,
 * Keycloak, Azure AD, ...). laravel/socialite ships provider-specific
 * drivers (github, google, ...) but no generic OIDC one, so this fills that
 * gap using the standard `/authorize`, `/token` and `/userinfo` endpoints
 * relative to the configured issuer base URL — full discovery-document
 * resolution (`/.well-known/openid-configuration`) is deliberately left
 * out; it's more than an MVP social-login flow needs and would add another
 * outbound HTTP call to every redirect.
 */
class OidcProvider extends AbstractProvider implements ProviderInterface
{
    /** @var list<string> */
    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    /**
     * @param  array<string, mixed>  $guzzle
     */
    public function __construct($request, $clientId, $clientSecret, $redirectUrl, private readonly string $baseUrl, array $guzzle = [])
    {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle);
    }

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(rtrim($this->baseUrl, '/').'/authorize', $state);
    }

    protected function getTokenUrl()
    {
        return rtrim($this->baseUrl, '/').'/token';
    }

    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get(rtrim($this->baseUrl, '/').'/userinfo', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => Arr::get($user, 'sub'),
            'nickname' => Arr::get($user, 'preferred_username'),
            'name' => Arr::get($user, 'name'),
            'email' => Arr::get($user, 'email'),
            'avatar' => Arr::get($user, 'picture'),
        ]);
    }
}
