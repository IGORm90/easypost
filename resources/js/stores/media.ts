import { defineStore } from 'pinia';
import { api } from '../api/client';

/**
 * Mirrors `MediaResource` (Task 27/28). `width`/`height` are populated
 * for images and `null` for video and for images whose dimensions could
 * not be read — see MediaResource's own docblock — so any grid reserves
 * layout space with `aspect-ratio` only when both are present, and
 * falls back to a fixed-size tile otherwise instead of collapsing.
 */
export interface MediaItem {
    id: string;
    url: string;
    thumbnail_url: string | null;
    mime: string;
    size_bytes: number;
    width: number | null;
    height: number | null;
    original_filename: string;
    created_at: string;
    /**
     * Client-only: true while an optimistic upload placeholder is still
     * in flight. Never present on anything the API returned — see
     * `upload()`.
     */
    pending?: boolean;
    /** Client-only: 0-100 upload progress for a pending placeholder. */
    progress?: number;
}

interface MediaListMeta {
    current_page: number;
    last_page: number;
}

interface MediaState {
    items: MediaItem[];
    page: number;
    hasMore: boolean;
    search: string;
    loading: boolean;
}

/**
 * Task 29's media library store, shared by `Media/Index.vue` (the
 * library page) and `MediaPicker.vue` (opened from the post editor).
 *
 * `items` is a flat, append-on-fetch list — one running view of however
 * many pages of the organization's library have been loaded so far.
 * `fetch()` appends the next page; pass `{ reset: true }` (a new search
 * term, or a fresh mount) to clear it and start over from page 1.
 *
 * `GET /api/media` is the one endpoint here that returns a paginated
 * Laravel resource collection (`{data: [...], meta: {...}}`); `POST
 * /api/media` wraps a single `MediaResource` the same shape one level
 * up (`{data: {...}}`) — see MediaController's docblock for why every
 * action responds through `->response()`, consistently, unlike most of
 * this API.
 */
export const useMediaStore = defineStore('media', {
    state: (): MediaState => ({
        items: [],
        page: 0,
        hasMore: true,
        search: '',
        loading: false,
    }),
    actions: {
        async fetch(options: { reset?: boolean } = {}): Promise<void> {
            if (options.reset) {
                this.items = [];
                this.page = 0;
                this.hasMore = true;
            }

            if (!this.hasMore || this.loading) {
                return;
            }

            this.loading = true;

            try {
                const { data } = await api.get<{ data: MediaItem[]; meta: MediaListMeta }>('/media', {
                    params: { page: this.page + 1, search: this.search || undefined },
                });

                this.items.push(...data.data);
                this.page = data.meta.current_page;
                this.hasMore = data.meta.current_page < data.meta.last_page;
            } finally {
                this.loading = false;
            }
        },

        setSearch(term: string): void {
            this.search = term;
        },

        /**
         * Upserts already-known media into `items` by id, without
         * touching anything else already there — used to seed the
         * store from `PostGroupResource`'s `posts[].value[].media`
         * (Task 28) when an existing post is loaded into the editor,
         * so `ThreadEditor.vue`'s attached-media thumbnails and
         * `MediaPicker.vue`'s "already selected" highlighting resolve
         * correctly even for media the picker itself has never fetched
         * a page containing.
         */
        hydrate(items: MediaItem[]): void {
            for (const item of items) {
                const index = this.items.findIndex((existing) => existing.id === item.id);

                if (index === -1) {
                    this.items.push(item);
                } else {
                    this.items.splice(index, 1, item);
                }
            }
        },

        /**
         * Uploads `file`, immediately placing a `pending` placeholder at
         * the FRONT of `items` (brief: "upload() adds an item to the
         * beginning of the list") so the caller sees feedback before the
         * request even settles — this is what makes a 100 MB video over
         * a slow connection show something other than a frozen button.
         * `onProgress` (0-100, from axios' `onUploadProgress`) is both
         * forwarded to the caller and mirrored onto the placeholder's
         * own `progress` field so any renderer of `items` can show a bar
         * without a second channel of its own.
         *
         * On success the placeholder is replaced in place (same index)
         * by the real `MediaItem` the server returned. On failure it is
         * spliced back OUT — no phantom tile survives a failed upload —
         * and the error is rethrown so the caller (MediaUploader.vue)
         * can surface it instead of failing silently.
         */
        async upload(file: File, onProgress?: (percent: number) => void): Promise<MediaItem> {
            const placeholder: MediaItem = {
                id: `pending-${crypto.randomUUID()}`,
                url: '',
                thumbnail_url: null,
                mime: file.type,
                size_bytes: file.size,
                width: null,
                height: null,
                original_filename: file.name,
                created_at: new Date().toISOString(),
                pending: true,
                progress: 0,
            };

            this.items.unshift(placeholder);

            const form = new FormData();
            form.append('file', file);

            try {
                const { data } = await api.post<{ data: MediaItem }>('/media', form, {
                    onUploadProgress: (event: { loaded: number; total?: number }) => {
                        if (!event.total) {
                            return;
                        }

                        const percent = Math.round((event.loaded / event.total) * 100);
                        placeholder.progress = percent;
                        onProgress?.(percent);
                    },
                });

                const index = this.items.findIndex((item) => item.id === placeholder.id);
                if (index !== -1) {
                    this.items.splice(index, 1, data.data);
                }

                return data.data;
            } catch (error) {
                const index = this.items.findIndex((item) => item.id === placeholder.id);
                if (index !== -1) {
                    this.items.splice(index, 1);
                }

                throw error;
            }
        },

        /**
         * Same optimistic-placeholder/rollback shape as `upload()`, for
         * `POST /api/media/from-url` — the library's other ingestion
         * path (Task 27). No upload progress: the browser sends a small
         * JSON body and waits for the server-side download, so there is
         * nothing meaningful to report client-side.
         */
        async uploadFromUrl(url: string): Promise<MediaItem> {
            const placeholder: MediaItem = {
                id: `pending-${crypto.randomUUID()}`,
                url: '',
                thumbnail_url: null,
                mime: '',
                size_bytes: 0,
                width: null,
                height: null,
                original_filename: url,
                created_at: new Date().toISOString(),
                pending: true,
            };

            this.items.unshift(placeholder);

            try {
                const { data } = await api.post<{ data: MediaItem }>('/media/from-url', { url });

                const index = this.items.findIndex((item) => item.id === placeholder.id);
                if (index !== -1) {
                    this.items.splice(index, 1, data.data);
                }

                return data.data;
            } catch (error) {
                const index = this.items.findIndex((item) => item.id === placeholder.id);
                if (index !== -1) {
                    this.items.splice(index, 1);
                }

                throw error;
            }
        },

        /**
         * Deletes media by id. `DELETE /api/media/{id}` 409s when the
         * media is still attached to an unpublished post
         * (MediaController::destroy()) — a normal, expected outcome, not
         * a crash. That rejection (and any other) propagates to the
         * caller untouched (no `.response.status` branching here — see
         * MediaGrid.vue/Media/Index.vue for the 409-specific message),
         * and `items` is left untouched unless the server actually
         * confirmed the delete.
         */
        async remove(id: string): Promise<void> {
            await api.delete(`/media/${id}`);
            this.items = this.items.filter((item) => item.id !== id);
        },
    },
});
