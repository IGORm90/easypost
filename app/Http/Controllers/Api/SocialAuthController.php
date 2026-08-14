<?php

namespace App\Http\Controllers\Api;

use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use App\Services\Auth\SocialAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

/**
 * Handles GET /api/auth/oauth/{driver}/{redirect,callback}. `$driver` is
 * typed as SocialProvider (a backed enum) on both actions, so Laravel's
 * implicit enum route binding rejects any value outside
 * {github,google,oidc} with a 404 before either method body — and
 * therefore before any Socialite::driver() call — ever runs.
 *
 * Both routes are registered in routes/web.php, not routes/api.php — see
 * that file's comment on the oauth group for why: the callback is a
 * top-level navigation issued by the *provider*, not the SPA, so it can't
 * rely on Sanctum's Referer/Origin-based statefulApi() gate to get a
 * session.
 *
 * Uses Socialite's default *stateful* mode, not stateless(): redirect()
 * stores a random `state` value in the session, and user() verifies it
 * against the callback's `state` query parameter (throwing
 * InvalidStateException on mismatch, caught below). That's what stops an
 * attacker from crafting a callback URL and getting it completed inside a
 * victim's session — stateless() would remove this check entirely and
 * there'd be nothing else here replacing it.
 */
class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialAccountService $socialAccounts,
        private readonly AuthService $auth,
    ) {}

    public function redirect(SocialProvider $driver): JsonResponse
    {
        $url = Socialite::driver($driver->value)->redirect()->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * The post-login target is read from config('services.oauth_spa_redirect_url')
     * — never from a request parameter — so this can't become an open
     * redirect for an attacker-chosen destination.
     *
     * Talking to the provider (denied consent, an expired/replayed `code`,
     * a network blip, or the `state` mismatch above) is wrapped in its own
     * try/catch, deliberately scoped to just that call: a rejection raised
     * by SocialAccountService (e.g. the unverified-email 409) is a
     * deliberate application response, not a provider failure, and must
     * keep propagating as-is rather than being flattened into this generic
     * redirect.
     */
    public function callback(Request $request, SocialProvider $driver): RedirectResponse
    {
        try {
            /** @var SocialiteUser $socialiteUser */
            $socialiteUser = Socialite::driver($driver->value)->user();
        } catch (Throwable $e) {
            // $e's message can carry provider-echoed, attacker-influenced
            // text (e.g. an `error_description` query parameter) — logged
            // for our own debugging, never put into the redirect the
            // caller's browser receives.
            Log::warning('OAuth callback failed before an identity could be resolved.', [
                'driver' => $driver->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return redirect()->away($this->errorRedirectUrl());
        }

        $user = $this->socialAccounts->findOrCreateUser($driver->value, $socialiteUser);
        $this->auth->login($request, $user);

        return redirect()->away((string) config('services.oauth_spa_redirect_url'));
    }

    private function errorRedirectUrl(): string
    {
        return (string) config('services.oauth_spa_redirect_url').'?oauth_error=1';
    }
}
