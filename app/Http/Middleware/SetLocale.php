<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request into the caller's language for its whole lifetime.
 *
 * Without this, `App::setLocale()` was never called anywhere and every
 * server-rendered string went out in APP_LOCALE (`en`) — most visibly the
 * validation messages, which the SPA renders verbatim out of
 * `errors[field][0]` on seven different pages. A Russian user filled a
 * fully Russian register form and got "The email has already been taken."
 * underneath it.
 *
 * Order of preference:
 *  1. `users.locale` — an explicit choice the user made in their profile,
 *     so it wins over anything the browser happens to advertise.
 *  2. `Accept-Language` — the only signal available before sign-in, which
 *     is exactly where the register/login/reset forms live.
 *  3. Whatever `config('app.locale')` already was, left untouched.
 *
 * A value this app has no messages for is ignored rather than applied:
 * setting an unsupported locale would silently fall the whole response
 * back to `fallback_locale`, which is the failure this exists to end.
 */
final class SetLocale
{
    /**
     * Locales this app actually ships message files for. Mirrors
     * resources/js/i18n's SUPPORTED_LOCALES — LocaleParityTest keeps the
     * two trees themselves in step.
     */
    public const SUPPORTED = ['en', 'ru'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->supported($request->user()?->locale)
            ?? $this->fromAcceptLanguage($request);

        if ($locale !== null) {
            App::setLocale($locale);
        }

        return $next($request);
    }

    /**
     * Reads the highest-priority language the client advertises that this
     * app can actually serve. Symfony parses and q-sorts the header for
     * us, so `ru;q=0.9,en;q=0.8` picks `ru` rather than whichever came
     * first textually.
     */
    private function fromAcceptLanguage(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $supported = $this->supported(substr($language, 0, 2));

            if ($supported !== null) {
                return $supported;
            }
        }

        return null;
    }

    private function supported(mixed $locale): ?string
    {
        return is_string($locale) && in_array($locale, self::SUPPORTED, true) ? $locale : null;
    }
}
