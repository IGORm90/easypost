<?php

namespace App\Services\Posts;

use App\Models\Integration;
use App\Social\Contracts\DeclaresPostSettings;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\ProviderRegistry;
use Illuminate\Support\Facades\Validator;

/**
 * Decides whether a $chain of PostPayloadDto may be scheduled/published
 * to a given Integration's network. Every answer comes from that
 * network's ProviderCapabilities (resolved once, up front, via
 * ProviderRegistry) — this class never branches on
 * `$integration->provider_identifier` or a provider's `identifier()`,
 * per the project's Global Constraint enforced by
 * tests/Unit/Architecture/ProviderIsolationTest.php. Adding a new
 * provider never requires touching this file: it only needs an accurate
 * ProviderCapabilities.
 *
 * Pure: no DB writes, no HTTP, no queue dispatch. Reading
 * `$integration->provider_identifier` (a plain property, already loaded
 * on the model the caller passed in) and resolving the provider through
 * the container are the only things this class touches beyond plain
 * PHP — no other framework facade is used. Called twice in the pipeline:
 * once at scheduling time (Task 20) and again inside the publish job
 * (Task 30), so it must return the exact same verdict both times for the
 * same inputs.
 *
 * validate() never throws — see App\Exceptions\PostValidationException
 * for the wrapper callers can throw once they've decided a non-empty
 * result should abort the request/job.
 *
 * Per-segment vs. across-the-chain limits: $chain is a thread — each
 * PostPayloadDto is a distinct post/reply published individually on the
 * remote network (see PostPayloadDto's docblock). Every per-post limit
 * (maxCharacters, maxImages, maxVideos, allowedMimeTypes,
 * maxFileSizeBytes, allowsMixedMedia, requiresAltText) is therefore
 * checked per segment, never summed across the chain — summing would
 * reject legal threads (e.g. 3 segments x 2 images each on a
 * maxImages=4 network, which is fine since each segment only ever
 * carries 2). Only two things are inherently chain-wide and are checked
 * once per chain: supportsThreads (a chain of >1 segment is a thread at
 * all) and maxThreadLength (how many segments the thread itself may
 * contain).
 *
 * Media classification for maxImages/maxVideos: an attachment counts as
 * an image if its mime type starts with 'image/', a video if it starts
 * with 'video/', and counts toward NEITHER quota otherwise (Task 19
 * review, Finding 2). ProviderCapabilities deliberately has no third
 * bucket for other media kinds (audio, etc.), and folding an unknown
 * kind into imageCount would both consume a quota it doesn't belong to
 * and report a wrong `actual` on any resulting too_many_images error —
 * which Task 25's editor renders verbatim. An attachment of an
 * unrecognised kind is instead surfaced through the allowedMimeTypes
 * check below (unsupported_mime), which is the correct diagnosis for
 * it; if a provider's allowedMimeTypes ever included a non-image/video
 * kind (none currently do), such an attachment would pass the mime
 * check uncounted against any quota — an accepted, documented gap of
 * the current two-bucket capability model, not something this service
 * can close without ProviderCapabilities growing a field for it.
 *
 * File size: MediaDto::$sizeBytes is the only source read for
 * file_too_large — this service never stats $media->path (Task 19
 * review, Finding 1). Stat'ing the path silently degraded to "always
 * passes" the moment $path stopped being a locally-stat-able file (e.g.
 * Task 27 moving media to S3/MinIO, where $path becomes a storage key),
 * and made validate()'s verdict depend on which machine/mount the code
 * ran on — both violate this class's core contract. A null $sizeBytes
 * (size genuinely not captured when the MediaDto was built) is treated
 * as its own validation failure (`file_size_unknown`), never as "skip
 * the check" — an unknown size must not silently read as a small one.
 *
 * Character counting: uses mb_strlen($content, 'UTF-8'), i.e. counts
 * Unicode codepoints, not bytes (strlen() would badly over-count
 * Cyrillic and any other multi-byte script) and not grapheme clusters
 * (ext-intl's grapheme_strlen() is unavailable in this project's PHP
 * image — see task-19-report.md). This means a multi-codepoint emoji
 * built from a ZWJ sequence (e.g. a family emoji, which is several base
 * emoji joined by zero-width-joiner codepoints) counts as multiple
 * characters here, same as a plain multi-character string, even though
 * it renders as one glyph and some networks' own counters treat it as
 * one or two "characters" under their specific algorithm (e.g. X/Twitter's
 * weighted counting). A single non-ZWJ emoji (the overwhelming majority
 * of real-world usage) is one Unicode codepoint and is counted
 * correctly as 1. This is a deliberate, documented approximation: it is
 * conservative in the common case and the only inaccuracy is undercounting
 * ZWJ-sequence emoji relative to some networks' own weighting, not
 * Cyrillic/emoji in general.
 *
 * Formatting markers count too (Block 3). What is stored and counted is
 * the canonical body — `**bold**`, all ten characters — while the
 * network counts whatever its own syntax renders to (Telegram counts
 * `bold`, four, because the markup left in the tags). The gap is always
 * in the safe direction: this service can only ever refuse a post the
 * network would have accepted, never wave through one it would reject
 * on length. Counting the rendered form instead would mean running
 * every provider's translation here — which is precisely the
 * provider-specific branching this class exists without (see the
 * paragraph above about never touching `provider_identifier`) — to buy
 * a handful of characters back.
 */
final class PostValidationService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * @param  PostPayloadDto[]  $chain
     * @return list<array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}>
     */
    public function validate(Integration $integration, array $chain): array
    {
        // A channel can outlive the network it was connected to: a
        // provider removed from this install leaves its Integration rows
        // behind, and ProviderRegistry::get() answers those with an
        // UnknownProviderException — an InvalidArgumentException, so
        // nothing in the request pipeline maps it to anything but a 500.
        // A 500 tells the customer nothing and cannot be acted on; one
        // validation error names the real problem and lands on the
        // segment that carries it, exactly like every other code here.
        if (! $this->providers->has($integration->provider_identifier)) {
            return [$this->error(
                0,
                'provider_unavailable',
                'This channel\'s network is no longer available. Delete the channel, or reconnect it on a network this installation still supports.',
                limit: null,
                actual: null,
            )];
        }

        $provider = $this->providers->get($integration->provider_identifier);
        $capabilities = $provider->capabilities();

        // Block 5.0.6: a provider may also declare fields the POST itself
        // must carry (a subreddit, a title). Resolved here, once, exactly
        // like capabilities — and, exactly like capabilities, read as a
        // declaration rather than by asking who the provider is.
        $postSettings = $provider instanceof DeclaresPostSettings ? $provider->postSettings() : [];

        $errors = [
            ...$this->validateChainShape($capabilities, $chain),
        ];

        foreach ($chain as $index => $segment) {
            $errors = [...$errors, ...$this->validateSegment($capabilities, $index, $segment)];
            $errors = [...$errors, ...$this->validatePostSettings($postSettings, $index, $segment)];
        }

        return $errors;
    }

    /**
     * Enforces a provider's declared per-post settings against one
     * segment.
     *
     * Two error codes rather than one, because they send the customer to
     * different places: `post_setting_required` means a field was left
     * empty, `post_setting_invalid` means it was filled in with something
     * the network will not take. Both carry the field's key in `limit`,
     * which is what lets the editor put the message under the field
     * instead of in a list at the top of the form.
     *
     * The rules are run through Laravel's validator rather than
     * interpreted here: they are declared as ordinary rule strings, and
     * reimplementing even a subset of them ('max:300', 'url',
     * 'required_if') would be a second, worse validator that drifts from
     * the first.
     *
     * @param  list<array{key: string, type: string, required: bool, rules: list<string>, option?: string, dependsOn?: string}>  $postSettings
     * @return list<array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}>
     */
    private function validatePostSettings(array $postSettings, int $index, PostPayloadDto $segment): array
    {
        if ($postSettings === []) {
            return [];
        }

        $errors = [];

        foreach ($postSettings as $field) {
            $key = $field['key'];
            $value = $segment->settings[$key] ?? null;

            // A blank string is absence wearing a costume: an empty text
            // input posts '', and passing that on means the network
            // rejects the post later, in its own words, on its own
            // schedule.
            $missing = $value === null || (is_string($value) && trim($value) === '');

            if ($missing) {
                if ($field['required']) {
                    $errors[] = $this->error(
                        $index,
                        'post_setting_required',
                        "This network requires \"{$key}\" to be set on every post.",
                        limit: $key,
                        actual: null,
                    );
                }

                // An optional field left empty is finished with: there is
                // nothing to run rules against, and running them anyway
                // would turn 'string' into a de facto required.
                continue;
            }

            $validator = Validator::make([$key => $value], [$key => $field['rules']]);

            if ($validator->fails()) {
                $errors[] = $this->error(
                    $index,
                    'post_setting_invalid',
                    "The value given for \"{$key}\" is not accepted by this network.",
                    limit: $key,
                    actual: is_scalar($value) ? (string) $value : 'invalid',
                );
            }
        }

        return $errors;
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return list<array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}>
     */
    private function validateChainShape(ProviderCapabilities $capabilities, array $chain): array
    {
        $errors = [];
        $length = count($chain);

        if ($length > 1 && ! $capabilities->supportsThreads) {
            for ($index = 1; $index < $length; $index++) {
                $errors[] = $this->error(
                    $index,
                    'threads_not_supported',
                    "This network does not support threads; segment {$index} cannot be posted as a reply in a chain of {$length}.",
                    limit: 1,
                    actual: $length,
                );
            }
        }

        if ($capabilities->maxThreadLength !== null && $length > $capabilities->maxThreadLength) {
            for ($index = $capabilities->maxThreadLength; $index < $length; $index++) {
                $errors[] = $this->error(
                    $index,
                    'too_many_segments',
                    "Thread has {$length} segments, exceeding this network's maximum of {$capabilities->maxThreadLength}.",
                    limit: $capabilities->maxThreadLength,
                    actual: $length,
                );
            }
        }

        return $errors;
    }

    /**
     * @return list<array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}>
     */
    private function validateSegment(ProviderCapabilities $capabilities, int $index, PostPayloadDto $segment): array
    {
        $errors = [];

        $length = mb_strlen($segment->content, 'UTF-8');
        $imageCount = 0;
        $videoCount = 0;
        foreach ($segment->media as $media) {
            if (str_starts_with($media->mimeType, 'image/')) {
                $imageCount++;
            } elseif (str_starts_with($media->mimeType, 'video/')) {
                $videoCount++;
            }
            // Neither bucket for anything else -- see this class's
            // docblock ("Media classification for maxImages/maxVideos").
        }

        if (trim($segment->content) === '' && $segment->media === []) {
            $errors[] = $this->error(
                $index,
                'empty_content',
                'Post has no text content and no media attached.',
                limit: 'non-empty text or at least one media attachment',
                actual: 'empty',
            );
        }

        // Block 5.0.5: a network that publishes nothing without an
        // attachment (Instagram) must say so here rather than at publish
        // time. Deliberately separate from empty_content above: a segment
        // with real text and no picture is not empty, it is unpostable to
        // THIS network, and telling the customer "post has no content"
        // about a post they can see they wrote would send them looking in
        // the wrong place.
        if ($capabilities->requiresMedia && $segment->media === []) {
            $errors[] = $this->error(
                $index,
                'media_required',
                'This network requires at least one image or video on every post.',
                limit: 1,
                actual: 0,
            );
        }

        if ($length > $capabilities->maxCharacters) {
            $errors[] = $this->error(
                $index,
                'content_too_long',
                "Content is {$length} characters, exceeding the {$capabilities->maxCharacters} character limit.",
                limit: $capabilities->maxCharacters,
                actual: $length,
            );
        }

        if ($imageCount > $capabilities->maxImages) {
            $errors[] = $this->error(
                $index,
                'too_many_images',
                "Post has {$imageCount} images, exceeding the maximum of {$capabilities->maxImages}.",
                limit: $capabilities->maxImages,
                actual: $imageCount,
            );
        }

        if ($videoCount > $capabilities->maxVideos) {
            $errors[] = $this->error(
                $index,
                'too_many_videos',
                "Post has {$videoCount} videos, exceeding the maximum of {$capabilities->maxVideos}.",
                limit: $capabilities->maxVideos,
                actual: $videoCount,
            );
        }

        if (! $capabilities->allowsMixedMedia && $imageCount > 0 && $videoCount > 0) {
            $errors[] = $this->error(
                $index,
                'mixed_media_not_supported',
                'This network does not allow images and video in the same post.',
                limit: 'single media type per post',
                actual: "{$imageCount} image(s) and {$videoCount} video(s)",
            );
        }

        foreach ($segment->media as $media) {
            $errors = [...$errors, ...$this->validateMedia($capabilities, $index, $media)];
        }

        return $errors;
    }

    /**
     * @return list<array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}>
     */
    private function validateMedia(ProviderCapabilities $capabilities, int $index, MediaDto $media): array
    {
        $errors = [];

        if (! in_array($media->mimeType, $capabilities->allowedMimeTypes, true)) {
            $errors[] = $this->error(
                $index,
                'unsupported_mime',
                "Media type {$media->mimeType} is not supported by this network.",
                limit: implode(', ', $capabilities->allowedMimeTypes),
                actual: $media->mimeType,
            );
        }

        if ($media->sizeBytes === null) {
            $errors[] = $this->error(
                $index,
                'file_size_unknown',
                'This attachment has no known file size, so it cannot be checked against the file-size limit.',
                limit: $capabilities->maxFileSizeBytes,
                actual: 'unknown',
            );
        } elseif ($media->sizeBytes > $capabilities->maxFileSizeBytes) {
            $errors[] = $this->error(
                $index,
                'file_too_large',
                "File is {$media->sizeBytes} bytes, exceeding the {$capabilities->maxFileSizeBytes} byte limit.",
                limit: $capabilities->maxFileSizeBytes,
                actual: $media->sizeBytes,
            );
        }

        if ($capabilities->requiresAltText && ($media->altText === null || trim($media->altText) === '')) {
            $errors[] = $this->error(
                $index,
                'missing_alt_text',
                'This network requires alt text on every media attachment.',
                limit: 'alt text required',
                actual: 'missing',
            );
        }

        return $errors;
    }

    /**
     * @return array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}
     */
    private function error(int $index, string $code, string $message, int|string|null $limit, int|string|null $actual): array
    {
        return [
            'post_index' => $index,
            'code' => $code,
            'message' => $message,
            'limit' => $limit,
            'actual' => $actual,
        ];
    }
}
