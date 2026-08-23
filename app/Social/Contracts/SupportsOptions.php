<?php

namespace App\Social\Contracts;

use App\Models\Integration;
use App\Social\Exceptions\UnknownProviderOptionException;

/**
 * Optional capability: implement this on a provider whose network exposes
 * a dynamic, per-account list the user must pick from before (or after)
 * connecting a channel — e.g. Slack's list of channels the bot can post
 * to, Reddit's list of subreddits the account moderates, Pinterest's list
 * of boards. Providers with nothing to pick from simply don't implement
 * it.
 *
 * This is the single generic hook behind `GET
 * /api/integrations/{id}/options/{option}` (Task 13): the controller
 * knows nothing about what "channels" or "boards" mean, only that it may
 * ask a provider for named lists of selectable options. `$option` is an
 * opaque string the frontend and the provider agree on (e.g. 'channels');
 * the controller never branches on it. Task 15 is the first real
 * consumer, for Slack.
 */
interface SupportsOptions
{
    /**
     * Returns the selectable values for $option on this connected
     * integration (e.g. $option = 'channels' for Slack).
     *
     * Throws UnknownProviderOptionException if this provider does not
     * recognise $option — the controller turns that into a 404, never a
     * leaked exception/class name.
     *
     * $context (Block 5.0.6) carries the values a DEPENDENT list needs:
     * some option lists are not a property of the account at all but of
     * another choice already made on the same post — Reddit's post flairs
     * exist per subreddit, so `flairs` is unanswerable without knowing
     * which subreddit. It arrives from `?context[...]` on
     * `GET /api/integrations/{id}/options/{option}`, validated to a flat
     * map of strings and otherwise untouched: the controller does not
     * know what any key means. It is user-controlled input that providers
     * usually put in an outbound URL — treat it as such.
     *
     * An implementation that cannot answer without a context key it was
     * not given should return an empty list, not throw: the editor
     * requests a dependent list as soon as it renders the field, which is
     * before the customer has chosen the value it depends on.
     *
     * @param  array<string, string>  $context
     * @return array<int, array{value: string, label: string}>
     */
    public function options(Integration $integration, string $option, array $context = []): array;

    /**
     * Maps a settings key this provider declares as updatable (see
     * `DeclaresUpdatableSettings`) to the `$option` name whose list the
     * user picks its value from — e.g. `['channel_id' => 'channels']`.
     *
     * Final review, Critical 4: `GET /api/providers` publishes this map
     * so the settings UI can render a picker for exactly those settings
     * that have one, driven by a declared capability rather than by
     * matching on a provider identifier — which the frontend is forbidden
     * from doing outside its preview/registry layer, and which is why
     * Slack's `channel_id` had no UI at all and every Slack post failed.
     *
     * An option a user never picks a setting from (a list shown for some
     * other purpose entirely) simply is not listed here.
     *
     * @return array<string, string>
     */
    public function settingOptions(): array;
}
