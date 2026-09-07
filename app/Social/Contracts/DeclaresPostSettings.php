<?php

namespace App\Social\Contracts;

/**
 * Optional capability: implement this on a provider whose network needs
 * choices made per POST rather than per channel — Reddit cannot accept a
 * submission without a subreddit and a title, and X takes a
 * reply-permission setting that can differ from one post to the next.
 *
 * The distinction from `DeclaresUpdatableSettings` is the lifetime of
 * the answer, and it is not a shade of meaning: a channel setting
 * (a workspace's `channel_id`) is decided once and reused, and lives on the
 * `integrations` row; a post setting is part of the post, lives on
 * `posts.settings`, and may legitimately differ between two posts to the
 * same channel. Putting a subreddit in channel settings would mean one
 * connected Reddit account could only ever post to one subreddit.
 *
 * `posts.settings` has been carried end to end since Task 20 —
 * ValidatesPostChains accepts it, PostGroupService stores it,
 * PublishService hands it to the provider as PostPayloadDto::$settings —
 * but nothing ever said what belonged in it. That gap is why no editor UI
 * for it exists: the SPA is not allowed to know which network is which
 * (resources/js/__tests__/providerIsolation.spec.ts), so a Reddit-shaped
 * form could not legally be written by hand. This declaration is what
 * lets one generic component render every provider's fields.
 *
 * Consumed by:
 *   - `GET /api/providers` — publishes the declaration, minus `rules`
 *     (see below), for the editor to render;
 *   - `PostValidationService` — enforces `required` and `rules`, in the
 *     same pass and with the same error shape as every other "may this
 *     content go to this channel" rule, so scheduling and publishing
 *     reach the same verdict.
 */
interface DeclaresPostSettings
{
    /**
     * The per-post fields this provider needs, in the order the editor
     * should render them.
     *
     * Keys of one entry:
     *   - `key`      — the `posts.settings` key this field writes.
     *   - `type`     — 'text' | 'textarea' | 'select'. The editor's whole
     *                  vocabulary; a provider needing a widget outside it
     *                  is a reason to extend this list deliberately, not
     *                  to smuggle provider-specific markup into the SPA.
     *   - `required` — whether a post to this network is invalid without
     *                  it. Enforced server-side regardless of what the
     *                  editor does with the flag.
     *   - `rules`    — Laravel validation rules for the value. NOT
     *                  published to the client: a client that received
     *                  them would end up reimplementing the validator,
     *                  and the two copies would disagree the first time a
     *                  rule changed. The server is the only judge.
     *   - `option`   — optional; for `select`, the `SupportsOptions`
     *                  option name whose list the value is picked from.
     *   - `dependsOn`— optional; another field's `key`, when this field's
     *                  option list only exists for a chosen value of that
     *                  field (Reddit's flairs belong to one subreddit).
     *                  The editor passes the parent's value as the
     *                  option request's `context`, and clears this
     *                  field when the parent changes — a flair from the
     *                  previously selected subreddit is not a valid
     *                  value, it is a rejected publish.
     *
     * @return list<array{key: string, type: 'text'|'textarea'|'select', required: bool, rules: list<string>, option?: string, dependsOn?: string}>
     */
    public function postSettings(): array;
}
