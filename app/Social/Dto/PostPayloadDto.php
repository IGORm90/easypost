<?php

namespace App\Social\Dto;

/**
 * One post's worth of content to publish. A thread/chain is an array of
 * these (`SocialProviderInterface::post()`'s `$chain` parameter) — the
 * first element is the root post, subsequent elements are replies to the
 * previous one, in order. A single (non-threaded) post is a chain of one.
 *
 * Thread resumption ($replyToReleaseId): if post() throws
 * ProviderPartialFailureException partway through a chain, the
 * publishing pipeline (Task 30) retries with a NEW $chain containing
 * only the segments that never published, and sets
 * $replyToReleaseId on that new chain's first element to the
 * provider-side post id of the last segment that DID publish (i.e. the
 * $released PostResultDto::$releaseId immediately before the failure
 * point).
 *
 * Rules for providers:
 *  - When $replyToReleaseId is set, that segment MUST be attached as a
 *    reply to the post identified by that id on the remote network —
 *    never published as a new, detached thread root. A duplicate root
 *    is the worst outcome here (double-posts to the real network) and
 *    is strictly worse than failing loudly.
 *  - $replyToReleaseId is only ever set on the first element of a
 *    given $chain. Within one post() call, a provider threads its own
 *    segments together from the results it just produced (chain[1]
 *    replies to chain[0]'s freshly-created post, etc.) exactly as
 *    before — this field only bridges across separate post() calls.
 *  - If the network cannot reply to an arbitrary external post id (or
 *    the provider cannot resolve what it needs to do so), the provider
 *    MUST throw ProviderPermanentException rather than silently
 *    publish a detached post.
 *
 * Design note — one anchor field, not two: some networks' reply model
 * needs more than "the immediate parent" (e.g. Bluesky/AT Protocol
 * replies carry both a `parent` and a thread `root` reference).
 * $replyToReleaseId deliberately carries only the immediate parent's
 * id, not a separate root id. A provider whose network needs the root
 * too is expected to resolve it itself — e.g. by fetching the parent
 * post's own stored record from the network, which for a reply already
 * contains that post's `root` reference (and if the parent turns out
 * to BE the thread root, parent and root are the same id). That
 * resolution is genuinely provider-specific behaviour (AT Protocol's
 * root/parent split is not a concept every network shares), so per the
 * project's Global Constraint it belongs inside the provider adapter,
 * not as a second field every provider and every pipeline resumption
 * must carry regardless of whether their network needs it.
 */
final readonly class PostPayloadDto
{
    /**
     * @param  string  $content  Post body text, already validated against the target's ProviderCapabilities::$maxCharacters.
     * @param  MediaDto[]  $media  Attachments for this post/segment.
     * @param  array<string, mixed>  $settings  Provider-specific per-post options (e.g. a poll, a content warning) that don't belong in the generic contract.
     * @param  string|null  $replyToReleaseId  Thread-resumption anchor. See the class docblock; null on a normal (first-attempt) chain.
     */
    public function __construct(
        public string $content,
        public array $media = [],
        public array $settings = [],
        public ?string $replyToReleaseId = null,
    ) {}

    /**
     * Returns a new PostPayloadDto identical to this one except for
     * $replyToReleaseId. PostPayloadDto is immutable (final readonly),
     * so this is how the pipeline attaches a resumption anchor to an
     * already-built payload without touching its content/media.
     */
    public function withReplyToReleaseId(string $releaseId): self
    {
        return new self($this->content, $this->media, $this->settings, $releaseId);
    }
}
