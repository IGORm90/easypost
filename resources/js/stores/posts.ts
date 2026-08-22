import { defineStore } from 'pinia';
import { api } from '../api/client';

/** Mirrors IntegrationResource's calendar-relevant fields (Task 21's CalendarEntryResource nests this per post). */
export interface CalendarIntegration {
    id: string;
    provider_identifier: string;
    name: string;
    picture: string | null;
}

/** One channel-specific segment of a group, as CalendarEntryResource emits it. */
export interface CalendarPost {
    id: string;
    content: string;
    state: string;
    integration: CalendarIntegration;
}

export interface CalendarTag {
    id: string;
    name: string;
    color: string;
    /**
     * How many post groups carry this tag. Present only on `GET /api/tags`
     * — TagResource emits it with `whenCounted`, and the calendar's copy
     * of the same resource never loads the count. Optional here rather
     * than in a separate type so one shape serves both readers.
     */
    post_groups_count?: number;
}

/**
 * One calendar entry — a post group with its posts/channels and tags,
 * exactly as `CalendarEntryResource` (Task 21) shapes it. `publish_date`
 * is UTC (a Laravel `Carbon` cast serializes to `...Z`), matching what
 * `CalendarGrid.vue` feeds FullCalendar and what `moveTo()` below writes
 * back after a successful reschedule.
 */
export interface CalendarEntry {
    id: string;
    state: string;
    publish_date: string;
    /** Non-null while a public `/share/{token}` link is active — see `share()`/`unshare()` below. */
    share_token: string | null;
    tags: CalendarTag[];
    posts: CalendarPost[];
}

export interface CalendarFilters {
    customer?: string;
    tags?: string[];
}

interface PostsState {
    entries: CalendarEntry[];
}

/**
 * Task 24's calendar store. `entries` holds exactly one range's worth of
 * data at a time — `fetchRange()` replaces the array wholesale rather
 * than merging, so switching month/week/day (a new `from`/`to`) never
 * accumulates stale entries from a previous range. Callers (CalendarGrid
 * via Index.vue) are responsible for calling `fetchRange()` only when the
 * visible range actually changes — see CalendarGrid.vue's `datesSet`
 * handler — not on every render/tick.
 */
export const usePostsStore = defineStore('posts', {
    state: (): PostsState => ({
        entries: [],
    }),
    actions: {
        async fetchRange(from: string, to: string, filters: CalendarFilters = {}): Promise<void> {
            const { data } = await api.get<CalendarEntry[]>('/calendar', {
                params: { from, to, customer: filters.customer, tags: filters.tags },
            });
            this.entries = data;
        },

        /**
         * Optimistic reschedule (task-24 brief): `entry.publish_date` is
         * overwritten with `date`'s own ISO string *synchronously*,
         * before `PATCH /api/posts/{group}/date` even goes out, so a
         * dragged card stays where the user dropped it with no flicker.
         *
         * On failure — a 409 (the group holds a `release_id`, e.g. it was
         * published in the moments between load and drop — see
         * PostGroupService::guardEditable()) or a 422 (the dropped slot
         * failed `UpdatePostDateRequest`'s `after:now`) — `publish_date`
         * is restored to `original`, the exact string this entry carried
         * before the optimistic write, not a re-fetched value: a refetch
         * would mask bugs in this rollback and cost a round trip just to
         * undo a local mutation. The error is re-thrown so the caller
         * (CalendarGrid.vue) can distinguish the 409 race from other
         * failures and message it accordingly.
         *
         * An unknown `groupId` (already removed from `entries`, e.g. by a
         * concurrent `remove()`) is a silent no-op — there is nothing to
         * move and nothing to roll back.
         */
        async moveTo(groupId: string, date: Date): Promise<void> {
            const entry = this.entries.find((item) => item.id === groupId);

            if (!entry) {
                return;
            }

            const original = entry.publish_date;
            entry.publish_date = date.toISOString();

            try {
                const { data } = await api.patch<CalendarEntry>(`/posts/${groupId}/date`, {
                    publish_date: date.toISOString(),
                });
                entry.publish_date = data.publish_date;
            } catch (error) {
                entry.publish_date = original;
                throw error;
            }
        },

        /**
         * Optimistically removes the entry before `DELETE` settles, same
         * "splice out, splice back at the same index on failure" shape as
         * `useIntegrationsStore.remove()` (Task 17) — see that store for
         * why the index is preserved rather than re-appending at the end.
         */
        async remove(groupId: string): Promise<void> {
            const index = this.entries.findIndex((item) => item.id === groupId);

            if (index === -1) {
                return;
            }

            const [removed] = this.entries.splice(index, 1);

            try {
                await api.delete(`/posts/${groupId}`);
            } catch (error) {
                this.entries.splice(index, 0, removed);
                throw error;
            }
        },

        /**
         * Turns on the public share link and returns the URL to show the
         * user. `POST /api/posts/{group}/share` is idempotent-ish — it
         * mints a token only if there isn't one — so calling it on an
         * already-shared group simply returns the existing URL.
         *
         * The API deliberately answers with a finished URL, not a bare
         * token: ShareService builds it with `URL::to()` against the
         * server's own `APP_URL`. The frontend must not assemble that URL
         * from `window.location.origin`, or a deployment where the SPA is
         * served from a different host than `APP_URL` would hand out links
         * that 404. The token is sliced back out of the URL only to drive
         * the local "link is active" indicator — never to rebuild the URL.
         *
         * No optimistic write here, unlike `unshare()`: there is nothing
         * to show until the server has actually minted the token.
         */
        async share(groupId: string): Promise<string> {
            const { data } = await api.post<{ url: string }>(`/posts/${groupId}/share`);

            const entry = this.entries.find((item) => item.id === groupId);

            if (entry) {
                entry.share_token = data.url.split('/').pop() ?? null;
            }

            return data.url;
        },

        /**
         * Revokes the link. Same "write locally first, roll back to the
         * exact previous value on failure, re-throw" shape as `moveTo()`
         * and `remove()` — the caller decides how to message the failure.
         *
         * Unlike those two, an entry missing from `entries` is NOT a
         * no-op: the editor modal can be open on a group that is outside
         * the calendar's current range, and a revoke must still reach the
         * server there. Only the local rollback is skipped.
         */
        async unshare(groupId: string): Promise<void> {
            const entry = this.entries.find((item) => item.id === groupId);
            const original = entry?.share_token ?? null;

            if (entry) {
                entry.share_token = null;
            }

            try {
                await api.delete(`/posts/${groupId}/share`);
            } catch (error) {
                if (entry) {
                    entry.share_token = original;
                }

                throw error;
            }
        },

        /**
         * Copies a group. The server answers 201 with a PostGroupResource
         * describing the new DRAFT — same content, media, tags and
         * publish_date as the original, no publish history.
         *
         * Only the new id is returned, and nothing is written to
         * `entries`. That is not laziness: a PostGroupResource is a
         * different shape from the CalendarEntryResource this store
         * holds — it carries no `integration` objects and no tag names —
         * so a copy spliced in from it would render as a broken card.
         * The page refetches the visible range instead, which is cheap
         * because the copy always inherits the original's publish_date
         * and therefore always lands in that same range.
         *
         * Errors are re-thrown untouched. A plan-limit rejection is a
         * 402, and the api client's interceptor has already raised the
         * LimitToast for it by the time this rejects.
         */
        async duplicate(groupId: string): Promise<string> {
            const { data } = await api.post<{ id: string }>(`/posts/${groupId}/duplicate`);

            return data.id;
        },
    },
});
