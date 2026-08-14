<?php

namespace App\Services\Posts;

use App\Models\PostGroup;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Owns the public share link lifecycle for a `PostGroup` (Task 22):
 * minting/revoking `share_token`, and the public, unauthenticated lookup
 * by that token. The task-22 brief calls the public endpoint "the whole
 * point" of this task — this class is where every one of its risks is
 * actually handled: token strength/uniqueness, revocation, the
 * indistinguishable-404 property, throttling, and the two deliberate,
 * narrowly-named (`withoutGlobalScope('organization')`, never the
 * blanket `withoutGlobalScopes()`) escapes from tenant scoping — see
 * findForPublic() and generateToken() below for why each needs it and
 * why the named form matters for each.
 */
class ShareService
{
    /**
     * Character length of a minted token. Str::random() draws from
     * random_bytes() (a CSPRNG, not anything email/id/sequence-derived —
     * see InviteService::TOKEN_LENGTH and OAuthStateService for the same
     * choice elsewhere in this codebase), and at 64 characters drawn from
     * an ~62-character alphabet the token carries roughly 380 bits of
     * entropy — brute-forcing it is not a practical attack, throttling
     * below exists for the enumeration/probing case, not because the
     * token space itself is weak.
     */
    private const TOKEN_LENGTH = 64;

    /**
     * Throttle for GET /api/public/posts/{token}: this route has no
     * session and no organization header to key a normal per-user/per-
     * tenant limit off, so the only signal available is the caller's IP.
     * Keyed on IP alone (never on the token itself) — a limiter keyed by
     * token+IP would only slow down repeated requests for ONE token and
     * do nothing to stop an attacker sweeping through many different
     * token guesses from the same IP, which is exactly the enumeration
     * this exists to blunt. 30 requests/minute comfortably covers a real
     * viewer reloading a shared page (or a handful of people opening the
     * same link close together behind one NAT'd IP) while making a
     * brute-force sweep of the 64-character token space take effectively
     * forever.
     */
    private const MAX_ATTEMPTS_PER_MINUTE = 30;

    private const DECAY_SECONDS = 60;

    /**
     * Mints (or replaces) $group's share token and returns the public
     * URL for it. Loops on collision the same way InviteService does —
     * astronomically unlikely at this length, cheap to guard against
     * outright rather than let the column's unique index surface as an
     * unhandled 500.
     */
    public function generate(PostGroup $group): string
    {
        $group->share_token = $this->generateToken();
        $group->save();

        return URL::to("/share/{$group->share_token}");
    }

    /**
     * Revokes $group's share link by clearing the column. Deliberately
     * just NULLs it rather than deleting/recreating the group: any
     * request already in flight for the old token loses its match the
     * instant this commits (PostGroup::where('share_token', $token) no
     * longer finds a row), and — because a NULLed token is
     * indistinguishable from "never shared" from findForPublic()'s point
     * of view — a revoked token 404s exactly like one that never
     * existed, per the brief.
     */
    public function revoke(PostGroup $group): void
    {
        $group->share_token = null;
        $group->save();
    }

    /**
     * The public lookup. Two things make this method the one place in
     * the whole task where scoping is subtle, so both are handled here
     * and nowhere else:
     *
     * 1. The scope escape. `PostGroup` is BelongsToOrganization-scoped,
     *    and this endpoint runs with no session and no X-Organization
     *    header — OrganizationContext::id() is null for the entire
     *    request. The *ordinary* scoped query, `PostGroup::find(...)`,
     *    would therefore match nothing at all (BelongsToOrganization
     *    fails closed on a null context — see that trait's docblock),
     *    not merely "nothing this tenant owns" as it would for a
     *    same-tenant caller. This lookup is the one and only place in
     *    this feature that deliberately steps outside that — and it does
     *    so by NAME (`withoutGlobalScope('organization')`), never the
     *    blanket `withoutGlobalScopes()`: the named form strips exactly
     *    the one scope this method needs to bypass and leaves every
     *    OTHER global scope Eloquent may ever attach to `PostGroup` fully
     *    enforced. `PostGroup` has no soft-delete scope today, so the two
     *    forms happen to behave identically right now — but the blanket
     *    form is a silent trap for the day someone adds `SoftDeletes` to
     *    `PostGroup` (an ordinary thing to want in a scheduler): with
     *    `withoutGlobalScopes()`, a soft-deleted group would silently
     *    become publicly servable again through this exact query, with
     *    nothing here failing loudly to catch it. `withoutGlobalScope('organization')`
     *    has no such failure mode — a future soft-delete scope keeps
     *    applying automatically, the same protection IntegrationService's
     *    upsert lookup and TokenRefreshService already rely on for
     *    exactly this reason (see their docblocks). A public share link
     *    has to work precisely BECAUSE the caller has no tenant context,
     *    and share_token itself (unguessable, revocable) is what stands
     *    in for the tenant check everywhere else in this app — it was
     *    never meant to stand in for every OTHER scope this model might
     *    ever carry.
     *
     * 2. Indistinguishability. `firstOrFail()` on `share_token = $token`
     *    can't tell apart three different reasons for finding nothing —
     *    the token never existed, it existed but was revoked (NULLed by
     *    revoke() above), or the whole group was deleted (cascades to
     *    its `posts`/`comments` rows) — and that's exactly the point:
     *    all three collapse onto the identical
     *    `ModelNotFoundException`/404 with no distinguishing body, so a
     *    caller probing tokens learns nothing about which case they hit.
     *
     * Once the group itself is found, everything else this method
     * touches (`posts`, each post's `integration`) goes back through the
     * NORMAL BelongsToOrganization-scoped path — deliberately not a
     * second `withoutGlobalScopes()` call. `$group->organization_id` is
     * now known-good (it came straight off the row the unscoped lookup
     * above just found), so bracketing the eager load with
     * `OrganizationContext::set()`/`clear()` re-enters the tenant-scoped
     * path other code already trusts, confines the untrusted, no-context
     * request to exactly the one query above, and needs no
     * `withoutGlobalScopes()` sprinkled through relations that Task
     * 26/others will keep extending.
     */
    public function findForPublic(string $token, string $ip): PostGroup
    {
        $this->throttle($ip);

        /** @var PostGroup $group */
        $group = PostGroup::withoutGlobalScope('organization')->where('share_token', $token)->firstOrFail();

        OrganizationContext::set($group->organization);

        try {
            // 'posts.integration' alone is enough for ordering:
            // PostGroup::posts() already applies ->orderBy('order') itself
            // (see that relation), so there's no need to redeclare it.
            //
            // `withTrashed()` on the integration, though, is load-bearing
            // (final review, batch 4). `Integration` is soft-deleted and
            // disconnecting a channel is an everyday act — without this,
            // the relation resolves to null the moment any one of a
            // group's channels is disconnected, and PublicPostResource
            // reads `provider_identifier` straight off it: a 500 on a
            // public URL that was already handed to a client, for a post
            // that did go out exactly as the page says.
            //
            // The channel's name and picture as they were is the right
            // answer here: this page reports what was published, and that
            // history does not stop being true when the channel is
            // disconnected afterwards.
            $group->load(['posts.integration' => fn ($query) => $query->withTrashed()]);
        } finally {
            OrganizationContext::clear();
        }

        return $group;
    }

    /**
     * The second (and last) `withoutGlobalScope('organization')` call in
     * this file — unlike findForPublic()'s, this one serves no data at
     * all. It only asks "does ANY group, in any organization (including
     * one a caller has no access to), already hold this candidate
     * token?" and returns a bare boolean; nothing about the matched row,
     * if any, is ever read or exposed. A collision here is already
     * astronomically unlikely (see TOKEN_LENGTH's docblock) — this exists
     * purely so that vanishing chance surfaces as "generate a different
     * token" instead of an unhandled unique-constraint QueryException.
     */
    private function generateToken(): string
    {
        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (PostGroup::withoutGlobalScope('organization')->where('share_token', $token)->exists());

        return $token;
    }

    private function throttle(string $ip): void
    {
        $key = "public-share:{$ip}";
        $attempts = RateLimiter::hit($key, self::DECAY_SECONDS);

        if ($attempts > self::MAX_ATTEMPTS_PER_MINUTE) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }
    }
}
