<script lang="ts">
/** One comment on a post group, as `CommentResource` (Task 22) shapes it. */
export interface Comment {
    id: string;
    content: string;
    user: { id: string; name: string; email: string };
    /** Laravel's default serialization — ISO 8601 with microseconds, e.g. `2026-08-22T10:00:00.000000Z`. */
    created_at: string;
}
</script>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '../ui/Button.vue';
import ContentTextarea from '../editor/ContentTextarea.vue';
import Toast from '../ui/Toast.vue';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/auth';

/**
 * The internal comment thread on a post group (Task 1.6). The backend has
 * been complete since Task 22 and had no caller at all — this component
 * is the whole of the SPA's side of it.
 *
 * Three properties of the API decide the shape of this:
 *
 *  - There is no PUT and no DELETE, no `updated_at` in the payload and no
 *    replies. The thread is append-only, so there is deliberately no
 *    edit, delete or reply affordance here — that is the contract, not an
 *    omission.
 *  - `GET` answers a bare array, oldest first, with no pagination: the
 *    whole thread arrives at once and is rendered in the order given.
 *  - The resource carries the author's email for teammates, but the name
 *    is what identifies a person on a screen someone else may be looking
 *    at, so only the name is rendered.
 *
 * It talks to `api` directly instead of going through a store, the same
 * way PostEditorModal.vue loads its own group: the thread lives exactly
 * as long as the modal around it, and nothing else in the app reads it.
 */
const props = defineProps<{ groupId: string }>();

const { t, locale } = useI18n();
const auth = useAuthStore();

/** `StoreCommentRequest`: `content` is `max:5000`. */
const CONTENT_LIMIT = 5000;

const comments = ref<Comment[]>([]);
const draft = ref('');
const loading = ref(false);
const sending = ref(false);
const error = ref<string | null>(null);
/** Set when the thread itself could not be read, so an empty list is not mistaken for "no comments". */
const loadFailed = ref(false);

const canSend = computed(() => {
    // Code points, matching the server's `mb_strlen` — the same reason
    // ContentTextarea.vue counts this way.
    const size = [...draft.value.trim()].length;

    return size > 0 && size <= CONTENT_LIMIT;
});

/**
 * The API sends a raw ISO string with microseconds; showing it verbatim
 * would put a machine timestamp in front of a human. Formatted in the
 * reader's own zone rather than the poster's — unlike a publish time,
 * which belongs to the schedule, "when was this said" is only ever
 * relative to whoever is reading it.
 */
function formatTime(iso: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: auth.user?.timezone ?? 'UTC',
    }).format(new Date(iso));
}

/** First letter of the author's name — CommentResource carries no avatar, and there is nowhere to get one. */
function monogram(name: string): string {
    return [...name.trim()][0]?.toUpperCase() ?? '?';
}

async function load(): Promise<void> {
    loading.value = true;

    try {
        const { data } = await api.get<Comment[]>(`/posts/${props.groupId}/comments`);
        comments.value = data;
        loadFailed.value = false;
        error.value = null;
    } catch {
        loadFailed.value = true;
        error.value = t('comments.loadError');
    } finally {
        loading.value = false;
    }
}

// Once, when the thread mounts. No polling: a post's comments are not a
// chat, and a timer here would keep firing for as long as the editor
// stays open on someone's second monitor.
onMounted(load);

async function send(): Promise<void> {
    if (!canSend.value || sending.value) {
        return;
    }

    sending.value = true;
    error.value = null;

    // Nothing goes into the list until the server has the row: an
    // optimistic append would have to invent an id and an author, and
    // the only thing it would buy is one frame. The box is cleared on
    // success and left alone on failure, so a flaky network costs the
    // user nothing they typed.
    const content = draft.value.trim();

    try {
        const { data } = await api.post<Comment>(`/posts/${props.groupId}/comments`, { content });

        // Appended, not re-fetched: the response is the saved row, and
        // the thread is ordered oldest-first, so the end is where it goes.
        comments.value = [...comments.value, data];
        draft.value = '';
    } catch {
        error.value = t('comments.sendError');
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <section class="flex flex-col gap-3 border-t border-border pt-4">
        <div class="flex flex-col gap-0.5">
            <h3 class="text-sm font-medium text-text">{{ t('comments.title') }}</h3>
            <p class="text-xs text-muted">{{ t('comments.intro') }}</p>
        </div>

        <Toast v-if="error" variant="danger" @dismiss="error = null">{{ error }}</Toast>

        <p v-if="loading" class="text-sm text-muted">{{ t('ui.loading') }}</p>

        <div v-else-if="!loadFailed && comments.length === 0" class="flex flex-col gap-1">
            <p class="text-sm text-text">{{ t('comments.empty') }}</p>
            <p class="text-sm text-muted">{{ t('comments.emptyHint') }}</p>
        </div>

        <ul v-else-if="comments.length > 0" class="flex flex-col gap-3">
            <li v-for="comment in comments" :key="comment.id" data-test="comment-row" class="flex gap-2">
                <span
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent"
                    aria-hidden="true"
                >
                    {{ monogram(comment.user.name) }}
                </span>
                <div class="flex min-w-0 flex-col gap-0.5">
                    <p class="flex flex-wrap items-baseline gap-2">
                        <span class="text-sm font-medium text-text">{{ comment.user.name }}</span>
                        <span data-test="comment-time" class="text-xs text-muted">{{ formatTime(comment.created_at) }}</span>
                    </p>
                    <!--
                        Plain interpolation, never v-html: CommentResource
                        stores the content exactly as typed and says so —
                        escaping it for display is this renderer's job.
                    -->
                    <p class="whitespace-pre-line break-words text-sm text-text">{{ comment.content }}</p>
                </div>
            </li>
        </ul>

        <form data-test="comment-form" class="flex flex-col gap-2" @submit.prevent="send">
            <div data-test="comment-input">
                <ContentTextarea
                    id="comment-content"
                    v-model="draft"
                    :label="t('comments.inputLabel')"
                    :limit="CONTENT_LIMIT"
                    :rows="3"
                />
            </div>
            <div>
                <Button
                    data-test="comment-submit"
                    type="submit"
                    variant="secondary"
                    :loading="sending"
                    :disabled="!canSend"
                >
                    {{ t('comments.submit') }}
                </Button>
            </div>
        </form>
    </section>
</template>
