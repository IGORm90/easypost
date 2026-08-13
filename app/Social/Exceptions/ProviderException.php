<?php

namespace App\Social\Exceptions;

use Throwable;

/**
 * Marker interface implemented by every exception a provider is
 * expected to throw from a live network call (post(), authenticate(),
 * refresh(), uploadMedia()): ProviderTransientException,
 * ProviderPermanentException, ProviderAuthException,
 * ProviderRateLimitException, ProviderPartialFailureException.
 *
 * The publishing pipeline (Task 30) catches each specific type first
 * for its own decision (retry / fail permanently / mark
 * refresh_needed / wait retryAfterSeconds / persist-partial-and-fail-
 * remainder). Catching this interface afterwards is the fallback: a
 * provider implementation that throws something it forgot to translate
 * into one of the specific types still lands in a handled, logged path
 * instead of crashing the job uncaught.
 *
 * UnknownProviderException deliberately does NOT implement this: it
 * signals a caller/config bug (ProviderRegistry::get() called with an
 * unregistered identifier) discovered before any provider call is even
 * attempted, not a failure from a live provider call.
 */
interface ProviderException extends Throwable {}
