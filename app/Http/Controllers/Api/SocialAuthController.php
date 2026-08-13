<?php

namespace App\Http\Controllers\Api;

use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use App\Services\Auth\SocialAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Handles GET /api/auth/oauth/{driver}/{redirect,callback}. `$driver` is
 * typed as SocialProvider (a backed enum) on both actions, so Laravel's
 * implicit enum route binding rejects any value outside
 * {github,google,oidc} with a 404 before either method body — and
 * therefore before any Socialite::driver() call — ever runs.
 *
 * Uses Socialite's default *stateful* mode, not stateless(): these routes
 * sit behind bootstrap/app.php's statefulApi() middleware, so a session is
 * already available for SANCTUM_STATEFUL_DOMAINS callers, and redirect()
 * stores a random `state` value in it that user() verifies against the
 * callback's `state` query parameter (throwing InvalidStateException on
 * mismatch). That's what stops an attacker from crafting a callback URL
 * and getting it completed inside a victim's session — stateless() would
 * remove this check entirely and there'd be nothing else here replacing it.
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
     */
    public function callback(Request $request, SocialProvider $driver): RedirectResponse
    {
        /** @var SocialiteUser $socialiteUser */
        $socialiteUser = Socialite::driver($driver->value)->user();

        $user = $this->socialAccounts->findOrCreateUser($driver->value, $socialiteUser);
        $this->auth->login($request, $user);

        return redirect()->away((string) config('services.oauth_spa_redirect_url'));
    }
}
