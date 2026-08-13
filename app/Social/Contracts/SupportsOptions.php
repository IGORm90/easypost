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
     * @return array<int, array{value: string, label: string}>
     */
    public function options(Integration $integration, string $option): array;
}
