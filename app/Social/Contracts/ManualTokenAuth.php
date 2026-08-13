<?php

namespace App\Social\Contracts;

/**
 * Optional marker: implement this on a provider whose "authorization" is
 * the user manually supplying a pre-issued credential (e.g. a Telegram
 * bot token pasted into a form) rather than completing a browser
 * redirect-based OAuth consent screen. Every provider still implements
 * `generateAuthUrl()`/`authenticate()` uniformly (see
 * SocialProviderInterface) — this marker only changes what `GET
 * /api/providers` reports as `auth_type`, which is what the frontend
 * (Task 17) uses to decide whether to open a popup and wait for a
 * redirect, or render a form asking for the credential directly.
 *
 * Providers that don't implement this report `auth_type: 'oauth'` (the
 * default, standard redirect-based flow). Implementing it switches that
 * to `auth_type: 'manual'`. No method is required on this interface — it
 * is a pure type marker, detected via `instanceof` in
 * IntegrationController, so the controller never branches on a specific
 * provider's identity.
 */
interface ManualTokenAuth {}
