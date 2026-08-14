import { defineStore } from 'pinia';
import { api } from '../api/client';
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

interface AuthState {
    user: AuthUser | null;
    organizations: AuthOrganization[];
    /** True once fetchMe() has resolved (successfully or not) at least once — lets the router guard fetch session state exactly once per app load. */
    ready: boolean;
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
        ready: false,
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
                this.ready = true;
                await router.push({ name: 'login' });
            }
        },

        /**
         * Refreshes session state from the server. A 401 (no session) is
         * a normal outcome here, not an error to surface — it just means
         * `user` becomes null, same as never having logged in.
         */
        async fetchMe(): Promise<void> {
            try {
                const { data } = await api.get<{ user: AuthUser; organizations: AuthOrganization[] }>('/auth/me');
                this.user = data.user;
                this.organizations = data.organizations;
            } catch {
                this.user = null;
                this.organizations = [];
            } finally {
                this.ready = true;
            }
        },
    },
});
