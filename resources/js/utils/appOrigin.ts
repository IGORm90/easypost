/**
 * Where the app (API, session cookie, login) lives, as the server told the
 * page in `<meta name="app-url">` (config('app.url')).
 *
 * In production the landing page is served from its own host, but the session
 * cookie belongs to the app host: asking `/api/auth/me` on the landing host can
 * never see a session, so the landing page would show "Sign in" to a member who
 * is already signed in. Asking the app host directly can — see config/cors.php.
 *
 * Everywhere the app is its own origin (the app host itself, local development,
 * a single-host deploy) this returns the relative values, so nothing changes.
 */
function configuredOrigin(): string | null {
    const content = document.head.querySelector<HTMLMetaElement>('meta[name="app-url"]')?.content;

    if (!content) {
        return null;
    }

    try {
        const { origin } = new URL(content);

        return origin === window.location.origin ? null : origin;
    } catch {
        return null;
    }
}

/** Absolute origin of the app when this page is served from another host, otherwise ''. */
export function appUrl(): string {
    return configuredOrigin() ?? '';
}

/** `baseURL` for calls that must reach the app host even from the landing host. */
export function appApiBase(): string {
    return `${appUrl()}/api`;
}
