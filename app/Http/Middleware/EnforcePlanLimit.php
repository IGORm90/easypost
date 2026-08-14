<?php

namespace App\Http\Middleware;

use App\Enums\PlanLimit;
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

        $this->limits->check($organization, $limit);

        return $next($request);
    }
}
