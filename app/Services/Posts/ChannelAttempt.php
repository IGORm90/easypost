<?php

namespace App\Services\Posts;

use App\Models\Integration;
use App\Models\Post;
use App\Services\Media\Exceptions\MediaTemporaryFileException;
use App\Services\Media\MediaTempFiles;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsMedia;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\ProviderRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One channel's trip to the network. `prepare()` runs OUTSIDE any lock (copying
 * and pre-uploading media can be slow); `send()` runs inside the lifecycle's
 * lock, calls the provider, and CLASSIFIES what happened into a ChannelOutcome.
 * It writes nothing: recording the outcome, and the decision that the segments
 * are still ours to publish, belong to PostGroupLifecycle. That split is what
 * keeps the double-publish protection in one place while this class stays a
 * description of how a network behaves.
 *
 * Runs with no OrganizationContext (a queue worker), so every model read
 * removes the tenant scope by name.
 */
class ChannelAttempt
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly MediaTempFiles $mediaFiles,
    ) {}

    /**
     * Outside the lock. Never throws for a provider or media problem: that is
     * carried in the result, to be turned into an outcome inside the lock.
     *
     * @param  Collection<int, Post>  $pending
     */
    public function prepare(string $integrationId, Collection $pending): PreparedChannel
    {
        [$integration, $provider] = $this->channelProvider($integrationId);

        if ($integration === null || $provider === null) {
            return new PreparedChannel;
        }

        try {
            [$media, $temporaryFiles] = $this->prepareMedia($provider, $integration, $pending);

            return new PreparedChannel($media, $temporaryFiles);
        } catch (ProviderException|MediaTemporaryFileException $e) {
            return new PreparedChannel(failure: $e);
        }
    }

    public function discard(PreparedChannel $prepared): void
    {
        $this->mediaFiles->discard($prepared->temporaryFiles);
    }

    /**
     * Inside the lock. `$pending` are the segments the caller has locked and
     * still holds, in chain order; the outcome's `published` is keyed by their
     * position in it.
     *
     * @param  Collection<int, Post>  $pending
     */
    public function send(string $integrationId, string $postGroupId, Collection $pending, PreparedChannel $prepared): ChannelOutcome
    {
        $integration = Integration::withoutGlobalScope('organization')->find($integrationId);

        if ($integration === null) {
            return ChannelOutcome::failed(null, null, 'The connected channel no longer exists.');
        }

        if (! $this->registry->has($integration->provider_identifier)) {
            return ChannelOutcome::failed($integration, 'unknown_provider', 'This channel\'s network is no longer available on this installation.');
        }

        $provider = $this->registry->get($integration->provider_identifier);

        // The last segment of THIS channel already out, if any: a resumed chain
        // is threaded onto it rather than published as a second root.
        $anchor = Post::withoutGlobalScope('organization')
            ->where('post_group_id', $postGroupId)
            ->where('integration_id', $integrationId)
            ->whereNotNull('release_id')
            ->orderByDesc('order')
            ->value('release_id');

        $chain = $this->buildChain($pending, $anchor, $prepared->media);

        try {
            if ($prepared->failure !== null) {
                throw $prepared->failure;
            }

            $this->enforceProviderThrottle($integration->provider_identifier);

            $results = $provider->post($integration, $chain);

            $published = [];
            $allResolved = true;

            foreach ($pending as $index => $post) {
                if (array_key_exists($index, $results)) {
                    $published[$index] = $results[$index];
                } else {
                    $allResolved = false;
                }
            }

            return $allResolved
                ? ChannelOutcome::published($integration, $published)
                : ChannelOutcome::retry($integration, null, $published);
        } catch (ProviderPartialFailureException $e) {
            $published = [];

            foreach ($pending as $index => $post) {
                if (isset($e->published[$index])) {
                    $published[$index] = $e->published[$index];
                }
            }

            return ChannelOutcome::retry($integration, 'partial', $published);
        } catch (ProviderRateLimitException $e) {
            return ChannelOutcome::retry($integration, 'rate_limit', [], $e->retryAfterSeconds > 0 ? $e->retryAfterSeconds : null);
        } catch (ProviderTransientException) {
            return ChannelOutcome::retry($integration, 'transient');
        } catch (MediaTemporaryFileException $e) {
            return ChannelOutcome::failed($integration, 'media', $e->getMessage());
        } catch (ProviderPermanentException $e) {
            return ChannelOutcome::failed($integration, 'permanent', $e->getMessage());
        } catch (ProviderAuthException $e) {
            return ChannelOutcome::failed($integration, 'auth', $e->getMessage(), flagsRefresh: true);
        }
    }

    /**
     * QUEUE.md Task 7: a shared, cross-tenant, per-provider publish
     * budget, enforced centrally so a burst of posts across many
     * organizations for the SAME network still respects one ceiling —
     * e.g. Reddit's or LinkedIn's own rate limit, which every tenant on
     * this installation shares regardless of which one happens to hit
     * it first.
     *
     * Deliberately NOT job middleware, despite that being QUEUE.md's own
     * literal suggestion: `PublishPostJob` publishes a whole `PostGroup`
     * across potentially SEVERAL channels/providers in one `handle()`
     * call (see `publishGroup()`'s per-integration loop above) — a
     * single job-level rate limit cannot express "channel A's provider
     * is over budget, channel B's isn't" for the same job execution.
     * This method is called from exactly the place that already
     * isolates one provider's one network call —
     * `publishLockedChannel()`, immediately before `$provider->post()` —
     * and reuses the EXISTING `ProviderRateLimitException` ->
     * `PublishRetryNeededException` -> `PublishPostJob::release()`
     * pipeline verbatim: throwing here is indistinguishable, to every
     * caller up the stack, from the network itself having returned a
     * 429. No new job middleware, no new catch arm, no new retry logic.
     *
     * `RateLimiter` (cache-backed), not a bespoke counter: already
     * atomic and already shared across every worker/tenant process
     * without any locking primitive of its own — the same reason
     * `MissedPostsService`'s probe and `TokenRefreshService::refresh()`'s
     * lock (see that method's docblock) both reach for `Cache` rather
     * than an in-process counter.
     *
     * `config('social.rate_limits')`, not `config('publishing.php')` —
     * that file's own docblock states it is deliberately provider-
     * agnostic; a table keyed by provider identifier belongs in
     * `config/social.php`, which already declares itself "the ONLY
     * place provider identifiers are wired." No entry for a given
     * identifier means unthrottled — every provider this install ships
     * starts that way until a real ceiling is tuned in.
     *
     * Post-review fix (round 4): hit-then-check, not check-then-hit.
     * `RateLimiter::hit()` is a single atomic cache increment (Redis'
     * `INCRBY`, see `RedisStore::increment()`) whose RETURN VALUE is the
     * post-increment count including this attempt — the exact idiom
     * `AuthService::attemptLogin()` already documents fixing this same
     * class of bug for the login throttle: "the previous
     * tooManyAttempts() (read) ... hit() (write) sequence left a window
     * where concurrent requests could each read a stale 'not yet at the
     * limit' count before any of them recorded a hit, letting a burst
     * slip past MAX_ATTEMPTS." `PasswordResetService`,
     * `EmailVerificationService` and `ShareService` all follow the same
     * shape. This method was the one outlier that reintroduced the
     * separate-read-then-write anti-pattern its own codebase already
     * warns against — two concurrent publish workers could both pass a
     * `tooManyAttempts()` read before either recorded a `hit()`, both
     * proceeding to call the network during the exact burst this limiter
     * exists to contain.
     *
     * Deliberately no compensating `decrement()` on the refused branch,
     * again matching `AuthService`. A refused attempt still counts, so
     * the counter can overshoot `max` under sustained load — harmless
     * here: `RateLimiter::increment()` sets the companion `:timer` key
     * with `Cache::add()`, i.e. only when absent, so the window's end is
     * fixed at the FIRST hit and an inflated count changes neither
     * `availableIn()` nor when the budget resets. A `decrement()` would
     * buy nothing and add a second round-trip plus a new "died between
     * the two calls" failure mode.
     */
    private function enforceProviderThrottle(string $providerIdentifier): void
    {
        $limit = config("social.rate_limits.{$providerIdentifier}");

        if ($limit === null) {
            return;
        }

        $key = "provider-throttle:{$providerIdentifier}";

        // The count returned already includes THIS attempt, so `>` (not
        // `>=`): with max => 1, the first caller sees 1 and proceeds, the
        // second sees 2 and is refused.
        $attempts = RateLimiter::hit($key, $limit['decay_seconds']);

        if ($attempts > $limit['max']) {
            throw new ProviderRateLimitException(
                "This installation's own shared rate limit for the {$providerIdentifier} network was reached; the network was not contacted.",
                RateLimiter::availableIn($key),
            );
        }
    }

    /**
     * Resolves this channel's integration and provider outside any
     * transaction, for the media preparation that has to happen before
     * one opens. Returns `[null, null]` when the integration is gone —
     * the transaction re-reads it and owns that outcome (see
     * publishLockedChannel()); this method never decides anything.
     *
     * @return array{0: Integration|null, 1: SocialProviderInterface|null}
     */
    private function channelProvider(string $integrationId): array
    {
        /** @var Integration|null $integration */
        $integration = Integration::withoutGlobalScope('organization')->find($integrationId);

        if ($integration === null) {
            return [null, null];
        }

        // A channel whose network this install no longer ships gets the
        // same treatment as a missing one here: no provider, so no media
        // preparation. publishLockedChannel() re-reads the integration
        // under the row locks and owns the outcome, exactly as for the
        // null case above — this method still decides nothing.
        if (! $this->registry->has($integration->provider_identifier)) {
            return [$integration, null];
        }

        return [$integration, $this->registry->get($integration->provider_identifier)];
    }

    /**
     * Makes every attachment on every pending segment actually
     * publishable — final-review batch 2, Critical 3 — and returns
     * `[dtosByPostId, temporaryFilePaths]`.
     *
     * Two steps, in this order, per attachment:
     *
     *  1. Copy the object off its storage disk into a real local file
     *     (`MediaTempFiles`) and point the DTO at THAT path. Every
     *     provider reads its bytes from the path — `file_get_contents()`,
     *     `filesize()`, `basename()` — and `Media::$path` is an S3/MinIO
     *     key, so before this step Telegram was sending zero-byte
     *     attachments.
     *  2. If the provider implements `SupportsMedia`, pre-upload it and
     *     keep the returned DTO (with its `$remoteId`), because
     *     Bluesky (and every other network that pre-uploads) references
     *     an already-uploaded handle from the post body and refuses —
     *     permanently — to publish a media DTO without one. Nothing in `app/` had ever made this
     *     call; `SupportsMedia`'s docblock claimed the editor did, and no
     *     such call existed anywhere.
     *
     * The caller discards every returned temp path in a `finally`,
     * including on the failure paths: this method's own `throw` still
     * returns nothing, so it cleans up what it created before rethrowing.
     *
     * @param  Collection<int, Post>  $pending
     * @return array{0: array<string, list<MediaDto>>, 1: list<string>}
     */
    private function prepareMedia(SocialProviderInterface $provider, Integration $integration, Collection $pending): array
    {
        $prepared = [];
        $temporaryFiles = [];

        try {
            foreach ($pending as $post) {
                $dtos = [];

                foreach ($post->media as $media) {
                    $localPath = $this->mediaFiles->copy($media);
                    $temporaryFiles[] = $localPath;

                    $dto = $media->toDto()->withPath($localPath);

                    if ($provider instanceof SupportsMedia) {
                        $dto = $provider->uploadMedia($integration, $dto);
                    }

                    $dtos[] = $dto;
                }

                if ($dtos !== []) {
                    $prepared[$post->id] = $dtos;
                }
            }
        } catch (\Throwable $e) {
            $this->mediaFiles->discard($temporaryFiles);

            throw $e;
        }

        return [$prepared, $temporaryFiles];
    }

    /**
     * @param  Collection<int, Post>  $pending
     * @param  array<string, list<MediaDto>>  $preparedMedia  Keyed by post id — see prepareMedia(). A segment with no attachments simply has no entry.
     * @return list<PostPayloadDto>
     */
    private function buildChain(Collection $pending, ?string $anchor, array $preparedMedia = []): array
    {
        $chain = [];

        foreach ($pending as $index => $post) {
            $payload = new PostPayloadDto(
                content: $post->content,
                // Never `$media->toDto()` directly: that DTO points at a
                // storage key no provider can read, and carries no
                // pre-upload handle. prepareMedia() is the only thing
                // that produces a publishable MediaDto.
                media: $preparedMedia[$post->id] ?? [],
                settings: $post->settings ?? [],
            );

            if ($index === 0 && $anchor !== null) {
                $payload = $payload->withReplyToReleaseId($anchor);
            }

            $chain[] = $payload;
        }

        return $chain;
    }
}
