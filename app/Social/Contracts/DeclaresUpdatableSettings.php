<?php

namespace App\Social\Contracts;

/**
 * Optional capability: implement this on a provider that has settings a
 * user may legitimately change on an ALREADY-CONNECTED channel, and
 * declare exactly which keys those are and what shape each must have.
 *
 * Why the declaration lives on the provider (final-review batch 2,
 * Important 4): `PUT /api/integrations/{id}` used to accept
 * `'settings' => ['sometimes','nullable','array']` and write it straight
 * through, so an org admin could repoint a channel's whole settings blob
 * with one request — changing the Mastodon instance host every future
 * publish connects to, or the target chat/channel a stored credential
 * posts into, with no connect flow and no OAuth involved. The fix cannot
 * be a hardcoded per-network list in the request class: provider names
 * appear only under `app/Social/Providers`, enforced by
 * `tests/Unit/Architecture/ProviderIsolationTest.php`. So the provider
 * itself declares the keys, and `UpdateIntegrationRequest` asks whoever
 * this channel belongs to.
 *
 * NOT implementing this interface is the safe default and means exactly
 * "no setting on this channel may be changed through the API" — the
 * request layer rejects any `settings` key for such a provider. That is
 * deliberately the case for every provider whose settings were written by
 * the connect flow (the instance host, the chat id, the webhook's
 * channel): those are identity, not preference, and changing them turns
 * one credential into a key for a different target.
 */
interface DeclaresUpdatableSettings
{
    /**
     * Setting key => Laravel validation rules for that key's value, for
     * the keys a user may change after connecting. Anything not listed is
     * rejected outright rather than silently dropped, so a caller is
     * never told a change was saved when it wasn't.
     *
     * @return array<string, list<string>>
     */
    public function updatableSettings(): array;
}
