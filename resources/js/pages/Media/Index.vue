<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import AppLayout from '../../layouts/AppLayout.vue';
import Toast from '../../components/ui/Toast.vue';
import Button from '../../components/ui/Button.vue';
import Input from '../../components/ui/Input.vue';
import MediaGrid from '../../components/media/MediaGrid.vue';
import MediaUploader from '../../components/media/MediaUploader.vue';
import { useMediaStore } from '../../stores/media';

/**
 * Task 29's media library page. No primary CTA of its own (color.md
 * §3.1) — `MediaUploader.vue`'s own "browse" and URL-import controls
 * are secondary, same as the rest of this screen; nothing here competes
 * for the one-primary-per-screen budget.
 */
const { t } = useI18n();
const store = useMediaStore();

const loading = ref(false);
const loadingMore = ref(false);
const errorMessage = ref<string | null>(null);
const searchInput = ref('');

async function load(): Promise<void> {
    loading.value = true;
    errorMessage.value = null;

    try {
        await store.fetch({ reset: true });
    } catch {
        errorMessage.value = t('media.loadError');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function onSearchInput(value: string): void {
    searchInput.value = value;
    store.setSearch(value);

    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        void load();
    }, 300);
}

async function loadMore(): Promise<void> {
    loadingMore.value = true;
    errorMessage.value = null;

    try {
        await store.fetch();
    } catch {
        errorMessage.value = t('media.loadError');
    } finally {
        loadingMore.value = false;
    }
}

/**
 * `DELETE /api/media/{id}` 409s when the media is still attached to an
 * unpublished post (`MediaController::destroy()`) — an expected,
 * normal outcome, not a bug, so it gets its own specific message
 * (`media.deleteInUse`) rather than the generic failure one.
 */
async function remove(id: string): Promise<void> {
    if (!window.confirm(t('media.deleteConfirm'))) {
        return;
    }

    errorMessage.value = null;

    try {
        await store.remove(id);
    } catch (error) {
        errorMessage.value = isAxiosError(error) && error.response?.status === 409 ? t('media.deleteInUse') : t('media.deleteFailed');
    }
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex max-w-5xl flex-col gap-4">
            <h1 class="text-lg font-semibold text-text">{{ t('media.title') }}</h1>

            <Toast v-if="errorMessage" variant="danger" @dismiss="errorMessage = null">{{ errorMessage }}</Toast>

            <MediaUploader />

            <Input
                :model-value="searchInput"
                type="search"
                :aria-label="t('media.search')"
                :placeholder="t('media.search')"
                @update:model-value="onSearchInput"
            />

            <p v-if="loading" class="text-sm text-muted">{{ t('ui.loading') }}</p>
            <MediaGrid v-else :items="store.items" deletable :empty-message="t('media.empty')" @delete="remove" />

            <Button v-if="!loading && store.hasMore" variant="ghost" type="button" :loading="loadingMore" @click="loadMore">
                {{ t('media.loadMore') }}
            </Button>
        </div>
    </AppLayout>
</template>
