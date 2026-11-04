<?php

namespace App\Social\Providers;

use App\Social\Dto\MediaDto;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderTransientException;
use Illuminate\Http\Client\Response;

/**
 * The "create a container, poll it, publish it" flow Instagram and
 * Threads share (Block 5.4–5.6). Both networks accept a post only as a
 * two-step Graph operation: a media/text container is created first,
 * optionally polled until Meta finishes transcoding it, then published
 * as a second call that turns the container into a real post. Facebook
 * Page does not use this — it accepts a direct `/feed` or `/photos`
 * call — so this trait is not on UsesMetaGraph itself.
 *
 * Calls `$this->call()` — UsesMetaGraph's one wrapper around ProviderCall,
 * which owns the network-error, redirect and Graph-error handling — so this
 * trait is only usable by a provider that also uses UsesMetaGraph.
 */
trait PublishesViaMetaContainers
{
    /**
     * Both read from config rather than being fixed constants so a test
     * can shrink them to near-zero (`config(['services.meta.container_poll_*' => ...])`)
     * instead of a real suite run actually sleeping through 10 × 3s of
     * polling for the "never finishes" case. Defaults are the production
     * values: comfortably inside the 90s Horizon publish timeout even for
     * a slow-transcoding video, per this method's own docblock.
     */
    private function containerPollMaxAttempts(): int
    {
        return (int) config('services.meta.container_poll_max_attempts', 10);
    }

    private function containerPollDelaySeconds(): int
    {
        return (int) config('services.meta.container_poll_delay_seconds', 3);
    }

    /**
     * Both providers that use this trait publish only from a
     * network-fetchable URL, never an upload — MediaDto::$publicUrl's
     * docblock spells out why (it is permanent, unsigned, and only
     * reachable when AWS_URL is publicly routable). A null publicUrl
     * here is not "no media": PostValidationService's requiresMedia
     * check already guarantees a Media row exists; a null publicUrl at
     * this point means AWS_URL is not configured for the outside world,
     * which nothing at request time can retry its way out of.
     */
    protected function requirePublicUrl(MediaDto $media): string
    {
        if ($media->publicUrl === null || $media->publicUrl === '') {
            throw new ProviderPermanentException(
                "The {$this->name()} API needs a publicly reachable media URL, but AWS_URL is not configured for one (see MediaDto::\$publicUrl).",
            );
        }

        return $media->publicUrl;
    }

    /**
     * $node is the Graph edge a container is created on — Instagram's is
     * `media`, Threads' is `threads`; the two networks disagree on the
     * name of an otherwise identical operation, so the caller states it
     * rather than this trait guessing from which provider composed it.
     *
     * @param  array<string, mixed>  $params
     */
    protected function createContainer(string $node, string $ownerId, string $token, array $params): string
    {
        $body = $this->call(
            fn (): Response => $this->http()->asForm()->post($this->graphUrl("{$ownerId}/{$node}"), [...$params, 'access_token' => $token]),
            'creating a media container',
        );

        $id = $body['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new ProviderPermanentException("The {$this->name()} API did not return a container id.");
        }

        return $id;
    }

    /**
     * Polls a container's `status_code` until it reaches `FINISHED`,
     * bounded by attempt count rather than wall clock: the publish
     * worker's own Horizon timeout is 90s (BoundsHttpTimeouts'
     * docblock), so an unbounded wait would be killed by the queue
     * before it could raise a retryable error of its own. Exhausting the
     * attempts throws ProviderTransientException so PublishPostJob
     * retries the whole segment on a fresh worker, rather than blocking
     * this one until it is killed.
     */
    protected function awaitContainer(string $token, string $creationId): void
    {
        $maxAttempts = $this->containerPollMaxAttempts();

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $body = $this->call(
                fn (): Response => $this->http()->get($this->graphUrl($creationId), ['fields' => 'status_code', 'access_token' => $token]),
                'checking media processing status',
            );

            $status = $body['status_code'] ?? null;

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR') {
                throw new ProviderPermanentException("The {$this->name()} API failed to process the uploaded media.");
            }

            if ($attempt < $maxAttempts - 1) {
                sleep($this->containerPollDelaySeconds());
            }
        }

        throw new ProviderTransientException("The {$this->name()} API did not finish processing media in time; this publish will be retried.");
    }

    protected function publishContainer(string $node, string $ownerId, string $token, string $creationId): string
    {
        $body = $this->call(
            fn (): Response => $this->http()->asForm()->post($this->graphUrl("{$ownerId}/{$node}_publish"), [
                'creation_id' => $creationId,
                'access_token' => $token,
            ]),
            'publishing a media container',
        );

        $id = $body['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new ProviderPermanentException("The {$this->name()} API did not return a published post id.");
        }

        return $id;
    }
}
