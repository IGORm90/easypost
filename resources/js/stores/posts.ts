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

/**
 * One row of `GET /api/posts` — Block 2's list view, shaped by
 * `PostGroupListResource`. Deliberately NOT a `CalendarEntry`: the
 * server trims the text to `preview` and collapses a thread's segments
 * into a deduplicated `channels` list, so a page of rows costs a
 * fraction of the same groups rendered as calendar cards. `share_token`
 * and `state` are carried in both shapes because both surfaces show the
 * same two indicators.
 */
export interface PostListRow {
    id: string;
    state: string;
    publish_date: string | null;
    share_token: string | null;
    preview: string;
    channels: CalendarIntegration[];
    tags: CalendarTag[];
    comments_count: number;
}

export interface PostListFilters {
    /** `undefined` means "every state" — the API's `all`. */
    state?: string;
    customer?: string;
    tags?: string[];
}

interface PostsState {
    entries: CalendarEntry[];
    list: PostListRow[];
    listPage: number;
    listHasMore: boolean;
    listLoading: boolean;
    listFilters: PostListFilters;
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
        list: [],
        listPage: 0,
        listHasMore: true,
        listLoading: false,
        listFilters: {},
    }),
    actions: {
        /**
         * Every mutation below has to reach BOTH collections: the same
         * group can be on screen as a calendar card and as a list row
         * (the two views share this store, and switching between them
         * does not clear the other one). Looking the group up in
         * `entries` alone — which is what these actions used to do — made
         * an action taken from the list issue its request and then leave
         * the row sitting there unchanged, which reads as "nothing
         * happened".
         */
        findEverywhere(groupId: string): Array<CalendarEntry | PostListRow> {
            const found: Array<CalendarEntry | PostListRow> = [];

            const entry = this.entries.find((item) => item.id === groupId);
            const row = this.list.find((item) => item.id === groupId);

            if (entry) {
                found.push(entry);
            }

            if (row) {
                found.push(row);
            }

            return found;
        },

        /**
         * Block 2's list view. Appends the next page; pass
         * `{ reset: true }` (a filter change, a fresh mount) to clear
         * what is held and start from page 1 — the same shape
         * `useMediaStore.fetch()` uses, and for the same reason: the two
         * screens page through their lists identically, so they should
         * not do it differently.
         *
         * The page number never reaches the URL — only the mode and the
         * state filter do (see Calendar/Index.vue), so a forwarded link
         * opens at the top of the list rather than at whatever page the
         * sender had scrolled to, on a list whose contents will have
         * moved by then anyway.
         */
        async fetchList(options: { reset?: boolean } = {}): Promise<void> {
            if (options.reset) {
                this.list = [];
                this.listPage = 0;
                this.listHasMore = true;
            }

            if (!this.listHasMore || this.listLoading) {
                return;
            }

            this.listLoading = true;

            try {
                const { data } = await api.get<{ data: PostListRow[]; meta: { current_page: number; last_page: number } }>('/posts', {
                    params: {
                        page: this.listPage + 1,
                        state: this.listFilters.state,
                        customer: this.listFilters.customer,
                        tags: this.listFilters.tags,
                    },
                });

                this.list.push(...data.data);
                this.listPage = data.meta.current_page;
                this.listHasMore = data.meta.current_page < data.meta.last_page;
            } finally {
                this.listLoading = false;
            }
        },
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
            const entryIndex = this.entries.findIndex((item) => item.id === groupId);
            const rowIndex = this.list.findIndex((item) => item.id === groupId);

            if (entryIndex === -1 && rowIndex === -1) {
                return;
            }

            const [removedEntry] = entryIndex === -1 ? [] : this.entries.splice(entryIndex, 1);
            const [removedRow] = rowIndex === -1 ? [] : this.list.splice(rowIndex, 1);

            try {
                await api.delete(`/posts/${groupId}`);
            } catch (error) {
                if (removedEntry) {
                    this.entries.splice(entryIndex, 0, removedEntry);
                }

                if (removedRow) {
                    this.list.splice(rowIndex, 0, removedRow);
                }

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

            const token = data.url.split('/').pop() ?? null;

            for (const target of this.findEverywhere(groupId)) {
                target.share_token = token;
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
            const targets = this.findEverywhere(groupId);
            const originals = targets.map((target) => target.share_token);

            for (const target of targets) {
                target.share_token = null;
            }

            try {
                await api.delete(`/posts/${groupId}/share`);
            } catch (error) {
                targets.forEach((target, index) => {
                    target.share_token = originals[index];
                });

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
