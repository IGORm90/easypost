import { defineStore } from 'pinia';
import { useAuthStore } from './auth';

export interface Organization {
    id: string;
    name: string;
    slug: string;
    created_at: string;
    updated_at: string;
}

interface OrganizationState {
    current: Organization | null;
}

const STORAGE_KEY = 'organization_id';

/**
 * Tracks which organization is "current" and keeps `localStorage`'s
 * `organization_id` in sync — the same key `api/client.ts`'s request
 * interceptor reads on every write request to set `X-Organization`. This
 * store never sets that header itself: it only writes the localStorage
 * value the client already trusts.
 */
export const useOrganizationStore = defineStore('organization', {
    state: (): OrganizationState => ({
        current: null,
    }),
    actions: {
        /**
         * Picks "current" out of the auth store's already-fetched
         * membership list: the previously stored id if it's still a
         * membership, else the first membership, else null. Called after
         * the auth store's `organizations` is populated (see
         * router.ts), so switching to a page never shows a stale
         * organization from a different account.
         */
        syncFromAuth(): void {
            const auth = useAuthStore();
            const storedId = localStorage.getItem(STORAGE_KEY);
            const match = auth.organizations.find((org) => org.id === storedId);
            this.current = match ?? auth.organizations[0] ?? null;

            if (this.current) {
                localStorage.setItem(STORAGE_KEY, this.current.id);
            } else {
                localStorage.removeItem(STORAGE_KEY);
            }
        },

        /**
         * Switches the active organization: persists `id` to
         * `localStorage` first (so it takes effect on the very next
         * request, including the one fetchMe() below makes) and then
         * refreshes the auth store's session/membership data — the
         * "dependent data" this task's brief requires switching to
         * refresh.
         */
        async switchTo(id: string): Promise<void> {
            localStorage.setItem(STORAGE_KEY, id);

            const auth = useAuthStore();
            await auth.fetchMe();

            this.current = auth.organizations.find((org) => org.id === id) ?? null;
        },
    },
});
