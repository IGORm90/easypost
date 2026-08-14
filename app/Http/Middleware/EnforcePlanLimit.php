<?php

namespace App\Http\Middleware;

use App\Enums\PlanLimit;
use App\Models\Integration;
use App\Services\Billing\PlanLimitService;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware alias `plan:<key>` (`plan:channels`, `plan:posts`,
 * `plan:media_storage`) — Task 36's fast, NON-LOCKING pre-check. Must run
 * after the `organization` middleware (needs `OrganizationContext::get()`
 * already set), the same ordering requirement `role:` already carries —
 * see `EnsureOrganizationRole`'s docblock.
 *
 * This middleware guarantees NOTHING by itself. It runs before the
 * controller and its FormRequest, entirely outside any transaction, so
 * two genuinely concurrent requests can both observe "there's room" and
 * both pass through to the real creation. Its only job is to reject the
 * overwhelmingly common case — a single request against an organization
 * already at or over its limit — with a friendly 402 before this
 * application spends any work on validation, an OAuth round-trip to a
 * real provider, or a disk write. The actual guarantee (two racing
 * requests can never both create the last unit a limit of one allows)
 * comes from `PlanLimitService::withLimit()`, wrapped around the real
 * INSERT at each of the three call sites this middleware guards:
 * `IntegrationService::connect()`, `PostGroupService::create()`, and
 * `MediaUploadService::persist()`. Deleting this middleware would not
 * make the application unsafe — only slower to reject a request that was
 * always going to be rejected anyway.
 *
 * `channels` sits on `POST .../auth-url`, not on `POST .../{provider}
 * /callback`: the callback route deliberately has no `organization`
 * middleware (the organization for a connect is whatever
 * `OAuthStateService::consume()` resolves the `state` to — see that
 * class's and `IntegrationService`'s docblocks), so there is no
 * `OrganizationContext` for this middleware to read at that point in the
 * pipeline, and `consume()` cannot be called twice (it is single-use) just
 * to give this middleware something to check. `auth-url` is reached by
 * EVERY connect flow, OAuth and manual-token alike (see
 * `ConnectProviderModal.vue`), so gating it here still covers "starting a
 * connect" for every provider — it just means the real guard for the
 * callback itself is `IntegrationService::connect()`'s own `withLimit()`
 * call alone, not this middleware.
 *
 * Reconnect-aware for `channels` (Task 36 fix round 1, Critical 2):
 * `POST .../auth-url` is not only how a brand-new channel gets connected
 * — it is also the ONLY path `ChannelCard.vue`'s "Reconnect" button
 * offers for repairing a channel already stuck in `refresh_needed`
 * (`Channels/Index.vue::openReconnect()` -> `ConnectProviderModal.vue`
 * -> `stores/integrations.ts::connect()`, all the way through this same
 * route). `IntegrationService::connect()` deliberately *permits* that
 * reconnect — token refresh of an already-live row never raises
 * `channelCount()` — but this middleware runs before the controller ever
 * reaches that service, so without this check a free org sitting exactly
 * at its channel limit could 402 on the one path the UI gives it to fix
 * a broken channel, even though the eventual `withLimit()` call would
 * have allowed it. `isLiveReconnect()` below asks a narrower question
 * than `connect()`'s own `$increasesChannelCount`: "does the request
 * name a live, non-deleted integration that already belongs to this
 * organization, ON THIS SAME PROVIDER" — good enough for a friendly
 * pre-check (which, like the rest of this class, guarantees nothing
 * under concurrency and is never the enforcement point), without needing
 * this middleware to know anything about `internal_id`/upsert matching
 * the way `connect()` does. `posts`/`media_storage` have no equivalent
 * "reconnect" concept, so this only ever applies to the `channels` key.
 *
 * Round 1 re-review, residual 2: an earlier revision of this check
 * matched only `(organization_id, id)`, not `provider_identifier` — a
 * client could name any live integration in the organization, on ANY
 * provider, to skip the pre-check for a completely different provider's
 * `auth-url`. Not a limit bypass on its own (`connect()`'s own
 * `withLimit()` still 402s once the OAuth round-trip completes, since
 * that genuinely new provider connection really does raise
 * `channelCount()`), but it wastes a full OAuth round-trip before
 * failing late instead of rejecting up front. `provider_identifier` must
 * now match the route's own `{provider}` segment too.
 *
 * `media_storage`'s delta is the uploaded file's size in BYTES, read
 * directly off the raw request — this runs before `UploadMediaRequest`'s
 * validation, so if no file is present at all (or it reports no size),
 * this middleware does nothing and leaves rejecting the request entirely
 * to that validation, rather than guessing at a delta that doesn't exist.
 * `POST /api/media/from-url` is deliberately NOT gated by this middleware
 * at all (see routes/api.php): the size of a URL import is unknown until
 * the download completes, so there is nothing to pre-check before it
 * starts — that path relies entirely on `MediaUploadService::persist()`'s
 * own check()+withLimit() pair, same as the direct-upload path's real
 * guarantee.
 */
class EnforcePlanLimit
{
    /** @var array<string, PlanLimit> */
    private const array PARAM_MAP = [
        'channels' => PlanLimit::CHANNELS,
        'posts' => PlanLimit::POSTS_PER_MONTH,
        'media_storage' => PlanLimit::MEDIA_STORAGE_MB,
    ];

    public function __construct(private readonly PlanLimitService $limits) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        $limit = self::PARAM_MAP[$key] ?? null;

        if ($limit === null) {
            throw new InvalidArgumentException("Unknown plan limit middleware key: [{$key}].");
        }

        $organization = OrganizationContext::get();

        // No organization in context is not this middleware's problem to
        // solve — every route it is attached to also carries the
        // `organization` middleware ahead of it (auth-url, posts store,
        // media store), so this is defence in depth, not an expected
        // path: let the request through and whatever actually needs the
        // organization (the controller, the service) fail on its own
        // terms rather than this middleware guessing at a 402 it has no
        // basis for.
        if ($organization === null) {
            return $next($request);
        }

        if ($limit === PlanLimit::MEDIA_STORAGE_MB) {
            $file = $request->file('file');
            $size = $file?->getSize();

            if ($file === null || $size === false || $size === null || $size < 1) {
                return $next($request);
            }

            $this->limits->check($organization, $limit, $size);

            return $next($request);
        }

        if ($limit === PlanLimit::CHANNELS && $this->isLiveReconnect($request, $organization->id)) {
            return $next($request);
        }

        $this->limits->check($organization, $limit);

        return $next($request);
    }

    /**
     * True when `$request` names (`integration_id`) a live, non-deleted
     * `Integration` already belonging to `$organizationId`, ON THE SAME
     * PROVIDER as the route's own `{provider}` segment — see the class
     * docblock, and its "Round 1 re-review, residual 2" note for why the
     * provider match was added: without it, a client could name any live
     * integration in the organization to skip the pre-check for a
     * completely unrelated provider's `auth-url`. Deliberately does not
     * also match `internal_id` the way `IntegrationService::connect()`'s
     * own upsert lookup does: this is only a friendly pre-check, and the
     * real guard is that method's `withLimit()` call, which does not
     * consult this middleware's decision at all.
     */
    private function isLiveReconnect(Request $request, string $organizationId): bool
    {
        $integrationId = $request->input('integration_id');

        if (! is_string($integrationId) || $integrationId === '') {
            return false;
        }

        $provider = $request->route('provider');

        if (! is_string($provider) || $provider === '') {
            return false;
        }

        return Integration::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('provider_identifier', $provider)
            ->whereKey($integrationId)
            ->exists();
    }
}
