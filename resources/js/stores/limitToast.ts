import { defineStore } from 'pinia';

interface LimitToastState {
    /** The server's own, already-actionable message (see
     *  PlanLimitExceededException::describe()) — never re-worded or
     *  translated here; `null` means no limit toast is currently shown. */
    message: string | null;
}

/**
 * Global state for the "plan limit approaching" warning toast (Task 36).
 * Deliberately separate from any one page's local `Toast` usage
 * (Channels/Index.vue, PostEditorModal.vue, ...): a 402 can originate
 * from a channel connect, a post save, or a media upload — three
 * different components — and the warning belongs to the application, not
 * to whichever one happened to make the request. `api/client.ts`'s
 * response interceptor is the only writer of `message` (via
 * `showLimitToast()` below); `LimitToast.vue`, mounted once in
 * `AppLayout.vue`, is the only reader.
 */
export const useLimitToastStore = defineStore('limitToast', {
    state: (): LimitToastState => ({ message: null }),
    actions: {
        show(message: string): void {
            this.message = message;
        },
        dismiss(): void {
            this.message = null;
        },
    },
});

/**
 * Plain-function entry point `api/client.ts`'s interceptor calls, rather
 * than reaching into `useLimitToastStore()` itself — keeps the
 * interceptor from having to know this is backed by Pinia at all, and
 * gives tests one narrow seam to assert against (see client.spec.ts).
 * Requires an active Pinia instance (installed once, in app.ts, before
 * any real request can fire) — calling this with none active would throw
 * per Pinia's own contract, same as calling any other store outside a
 * component with no `app.use(pinia)` having run yet.
 */
export function showLimitToast(message: string): void {
    useLimitToastStore().show(message);
}
