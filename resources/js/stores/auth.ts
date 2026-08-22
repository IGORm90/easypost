import { defineStore } from 'pinia';
import { api } from '../api/client';
import { applyUserLocale } from '../i18n';
import router from '../router';

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    timezone: string;
    locale: string;
    email_verified_at: string | null;
    email_notifications?: boolean;
    created_at: string;
    updated_at: string;
}

export interface AuthOrganization {
    id: string;
    name: string;
    slug: string;
    created_at: string;
    updated_at: string;
}

/**
 * The real actor behind a borrowed session (Task 1.7). Reported by
 * `GET /api/auth/me` from the `impersonator_id` session key
 * ImpersonationService writes — there is no other way for the SPA to
 * learn that it is not who it appears to be.
 */
export interface Impersonator {
    id: string;
    name: string;
}

interface AuthState {
    user: AuthUser | null;
    organizations: AuthOrganization[];
    /** True once fetchMe() has resolved (successfully or not) at least once — lets the router guard fetch session state exactly once per app load. */
    ready: boolean;
    /** True when this user holds the instance-level `users.is_superadmin` privilege — NOT the per-tenant OrganizationRole::SUPERADMIN, which is a different thing and grants nothing here. */
    isSuperadmin: boolean;
    /** Non-null while this session is being borrowed from someone else — the only thing the impersonation banner can be built on. */
    impersonator: Impersonator | null;
    /** True when the server rejected a request with 401 after the user had been signed in — the login page explains the sign-out rather than leaving it unexplained. Cleared on the next successful login. */
    sessionExpired: boolean;
}

/**
 * The single source of truth for "who is signed in", replacing Task 3's
 * `localStorage`-only placeholder (`auth_session`) that router.ts used to
 * consult directly. Session state itself still lives server-side (the
 * Sanctum cookie, HttpOnly and never read from JS) — this store only
 * mirrors what GET /api/auth/me last reported about it.
 */
export const useAuthStore = defineStore('auth', {
    state: (): AuthState => ({
        user: null,
        organizations: [],
        isSuperadmin: false,
        impersonator: null,
        ready: false,
        sessionExpired: false,
    }),
    actions: {
        async login(email: string, password: string): Promise<void> {
            const { data } = await api.post<{ user: AuthUser; organizations: AuthOrganization[] }>('/auth/login', { email, password });
            this.user = data.user;
            // Not optional: `ready = true` below stops the router guard from
            // calling fetchMe(), so this array is the only membership list
            // the app sees until the next full page load. An empty one makes
            // the organization store clear `organization_id`, which strips
            // X-Organization off every request and 403s the whole session.
            this.organizations = data.organizations;
            this.ready = true;
            this.sessionExpired = false;
            applyUserLocale(data.user.locale);
        },

        /**
         * Always clears local state and redirects to /login, even if the
         * server call fails (network blip, already-expired session): a
         * user who clicked "log out" must never be left looking signed in.
         */
        async logout(): Promise<void> {
            try {
                await api.post('/auth/logout');
            } catch {
                // Deliberately swallowed: a failed server call (network
                // blip, already-expired session) must not stop the local
                // "log out" outcome below — see this method's docblock.
            } finally {
                this.user = null;
                this.organizations = [];
                // Session facts, so they die with the session: an
                // `isSuperadmin` left standing would offer the admin
                // link to whoever signs in next on this browser.
                this.isSuperadmin = false;
                this.impersonator = null;
                this.ready = true;
                // A deliberate log-out is not an expiry: never explain it
                // to the user as one.
                this.sessionExpired = false;
                await router.push({ name: 'login' });
            }
        },

        /**
         * Called by the API client's response interceptor when the server
         * answers 401 for a user the app still believed was signed in.
         * Drops every trace of the session locally — including the
         * `organization_id` the request interceptor would otherwise keep
         * attaching to requests made by whoever signs in next — and raises
         * the flag the login page reads to say why they are back there.
         */
        markSessionExpired(): void {
            this.user = null;
            this.organizations = [];
            this.isSuperadmin = false;
            this.impersonator = null;
            this.ready = true;
            this.sessionExpired = true;
            localStorage.removeItem('organization_id');
        },

        /**
         * Refreshes session state from the server. A 401 (no session) is
         * a normal outcome here, not an error to surface — it just means
         * `user` becomes null, same as never having logged in.
         */
        async fetchMe(): Promise<void> {
            try {
                const { data } = await api.get<{
                    user: AuthUser;
                    organizations: AuthOrganization[];
                    is_superadmin: boolean;
                    impersonator: Impersonator | null;
                }>('/auth/me');
                this.user = data.user;
                this.organizations = data.organizations;
                this.isSuperadmin = data.is_superadmin;
                this.impersonator = data.impersonator;
                applyUserLocale(data.user.locale);
            } catch {
                this.user = null;
                this.organizations = [];
                this.isSuperadmin = false;
                this.impersonator = null;
            } finally {
                this.ready = true;
            }
        },
    },
});
