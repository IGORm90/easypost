<?php

namespace App\Social\Contracts;

/**
 * Optional capability: implement this on a provider whose connect flow
 * needs values typed by the user before it can start.
 *
 * Two different moments, one declaration — which of the two applies is
 * decided by `auth_type`, which the frontend already knows:
 *  - a `ManualTokenAuth` provider's credentials, submitted straight to
 *    `POST /integrations/{provider}/callback` (a bot token, an app
 *    password);
 *  - an OAuth provider's pre-redirect parameters, without which
 *    `POST /integrations/{provider}/auth-url` cannot even build a url
 *    (an instance host).
 *
 * Why this exists (final review, Important 10): the connect modal carried
 * its own hardcoded `{ telegram: [...], bluesky: [...] }` field maps — a per-provider behaviour map living
 * outside the preview/registry layer, which the frontend's
 * provider-isolation guard was blind to only because the object keys were
 * unquoted. The shape of a provider's credential form is the provider's
 * own knowledge; the frontend's job is to render whatever it is told.
 *
 * NOT implementing this means "this provider needs nothing typed in
 * before connecting", which is the correct answer for a plain OAuth
 * redirect.
 */
interface DeclaresAuthFields
{
    /**
     * The fields to collect, in the order they should be shown.
     *
     * `type` is a rendering hint only — 'password' for a value that must
     * not be shoulder-surfed, 'text' otherwise. It is never a validation
     * signal: the provider's own `authenticate()`/`generateAuthUrl()`
     * remains the authority on what a value must actually look like.
     *
     * @return list<array{key: string, type: 'text'|'password'}>
     */
    public function authFields(): array;
}
