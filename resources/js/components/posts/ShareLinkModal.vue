<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from '../ui/Modal.vue';
import Button from '../ui/Button.vue';
import Input from '../ui/Input.vue';
import Toast from '../ui/Toast.vue';
import { usePostsStore } from '../../stores/posts';

/**
 * Creates, shows and revokes the public `/share/{token}` link for one
 * post group. Shared by both entry points — the calendar card's actions
 * menu and the post editor's header — so the copy, the confirmation and
 * the error handling exist once.
 *
 * `shareToken` is an input, not internal state: the owner of the group
 * (the calendar store's entry, or the editor's loaded group) already
 * knows whether a link is active, and re-deriving it here would mean a
 * request every time the modal opens. `changed` reports the new value
 * back so that owner stays in step.
 */
const props = defineProps<{
    open: boolean;
    groupId: string | null;
    shareToken: string | null;
}>();

const emit = defineEmits<{ close: []; changed: [string | null] }>();

const { t } = useI18n();
const posts = usePostsStore();

const token = ref<string | null>(props.shareToken);
/**
 * The URL exactly as the server built it, kept whenever we have it.
 * ShareService assembles it with `URL::to()` against the backend's own
 * `APP_URL`, which is the only source that is certainly right — a
 * deployment serving the SPA from a different host than `APP_URL` would
 * otherwise hand out links that 404.
 *
 * When the token arrived from a list response rather than from a fresh
 * `POST .../share`, there is no server-built URL to use and `shareUrl`
 * below falls back to this origin. That fallback is correct for every
 * normal deployment and merely best-effort for the split-host one — but
 * the alternative, an extra round trip on every open, is worse.
 */
const urlFromServer = ref<string | null>(null);
const busy = ref(false);
const copied = ref(false);
const error = ref<string | null>(null);

const shareUrl = computed(() => {
    if (urlFromServer.value) {
        return urlFromServer.value;
    }

    return token.value ? `${window.location.origin}/share/${token.value}` : '';
});

// Opening the modal on another post must never show the previous post's
// link, and a stale "Copied!" is worse than no feedback at all.
watch(
    () => [props.open, props.groupId, props.shareToken],
    () => {
        token.value = props.shareToken;
        urlFromServer.value = null;
        copied.value = false;
        error.value = null;
    },
);

async function create(): Promise<void> {
    if (!props.groupId) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        const url = await posts.share(props.groupId);
        urlFromServer.value = url;
        token.value = url.split('/').pop() ?? null;
        emit('changed', token.value);
    } catch {
        error.value = t('calendar.share.createError');
    } finally {
        busy.value = false;
    }
}

async function revoke(): Promise<void> {
    if (!props.groupId || !window.confirm(t('calendar.share.revokeConfirm'))) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        await posts.unshare(props.groupId);
        token.value = null;
        urlFromServer.value = null;
        copied.value = false;
        emit('changed', null);
    } catch {
        // The store has already rolled its own copy of the token back —
        // leaving the link on screen here keeps the two in agreement.
        error.value = t('calendar.share.revokeError');
    } finally {
        busy.value = false;
    }
}

async function copy(): Promise<void> {
    await navigator.clipboard.writeText(shareUrl.value);
    copied.value = true;
}
</script>

<template>
    <Modal :open="open" :title="t('calendar.share.title')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <Toast v-if="error" variant="danger" @dismiss="error = null">{{ error }}</Toast>

            <p class="text-sm text-muted">{{ t('calendar.share.description') }}</p>

            <!--
                color.md §4: whether the link is live is never signalled by
                colour — the icon is decorative and the state is spelled
                out in text right next to it.
            -->
            <p class="flex items-center gap-2 text-sm text-text">
                <svg class="h-4 w-4 shrink-0 text-muted" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        v-if="token"
                        d="M8.5 11.5a3.5 3.5 0 005 0l2.5-2.5a3.536 3.536 0 10-5-5l-1 1a.75.75 0 101.06 1.06l1-1a2.036 2.036 0 112.88 2.88l-2.5 2.5a2 2 0 01-2.88 0 .75.75 0 10-1.06 1.06zm3-3a3.5 3.5 0 00-5 0L4 11a3.536 3.536 0 105 5l1-1a.75.75 0 10-1.06-1.06l-1 1a2.036 2.036 0 11-2.88-2.88l2.5-2.5a2 2 0 012.88 0 .75.75 0 101.06-1.06z"
                    />
                    <path
                        v-else
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM7.28 6.22a.75.75 0 00-1.06 1.06L8.94 10l-2.72 2.72a.75.75 0 101.06 1.06L10 11.06l2.72 2.72a.75.75 0 101.06-1.06L11.06 10l2.72-2.72a.75.75 0 00-1.06-1.06L10 8.94 7.28 6.22z"
                        clip-rule="evenodd"
                    />
                </svg>
                {{ token ? t('calendar.share.active') : t('calendar.share.inactive') }}
            </p>

            <div v-if="token" class="flex flex-col gap-2">
                <Input
                    :model-value="shareUrl"
                    type="url"
                    :label="t('calendar.share.linkLabel')"
                    readonly
                />
                <div class="flex flex-wrap gap-2">
                    <Button variant="secondary" @click="copy">{{ copied ? t('ui.copied') : t('ui.copy') }}</Button>
                    <Button variant="danger" :loading="busy" @click="revoke">{{ t('calendar.share.revoke') }}</Button>
                </div>
            </div>

            <div v-else>
                <Button variant="primary" :loading="busy" @click="create">{{ t('calendar.share.create') }}</Button>
            </div>
        </div>
    </Modal>
</template>
