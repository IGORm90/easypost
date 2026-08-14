<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from '../ui/Modal.vue';
import Button from '../ui/Button.vue';
import Input from '../ui/Input.vue';
import Toast from '../ui/Toast.vue';
import MediaGrid from './MediaGrid.vue';
import MediaUploader from './MediaUploader.vue';
import { useMediaStore, type MediaItem } from '../../stores/media';
import type { ProviderCapabilities } from '../../stores/integrations';

/**
 * Opened from the post editor (`ThreadEditor.vue`, one instance per
 * segment) to pick media for that segment's `media_ids`.
 *
 * Selection is staged locally in `selection` — `modelValue` is only
 * emitted on `confirm()`, so closing without confirming (backdrop click,
 * the explicit Cancel button) discards whatever was toggled during this
 * session and leaves the caller's `media_ids` untouched.
 *
 * Order: **selection order IS `media_ids` order.** The Nth tile clicked
 * becomes the Nth id (`toggle()` pushes onto the end of `selection`);
 * deselecting and reselecting a tile moves it to the end. There is no
 * separate drag-to-reorder step — the numbered badge `MediaGrid` shows
 * on every selected tile (via its `order` prop) IS the position it will
 * occupy in `media_ids`, live, so the user always sees the order they're
 * building before confirming. `PostGroupService` (Task 28) persists
 * `media_ids` in exactly the order it receives them, so this is the one
 * place that order is decided.
 */
const props = defineProps<{
    open: boolean;
    modelValue: string[];
    /** This channel's own capabilities — used only for a best-effort client-side selection limit, see `limitReachedFor()`. */
    capabilities?: ProviderCapabilities;
}>();

const emit = defineEmits<{ 'update:modelValue': [string[]]; close: [] }>();

const { t } = useI18n();
const store = useMediaStore();

const selection = ref<string[]>([...props.modelValue]);
const searchInput = ref('');
const loadingMore = ref(false);
const loadError = ref(false);

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        selection.value = [...props.modelValue];
        searchInput.value = store.search;
        loadError.value = false;

        // `store.page === 0`, NOT `store.items.length === 0` (review
        // finding 2): PostEditorModal.vue hydrates the store with an
        // existing group's already-attached media before this picker
        // ever opens, so on the most common path — editing an existing
        // post and adding more media — `items` already has one or two
        // entries and the old length check skipped fetching page 1
        // entirely, leaving the user looking at only what was already
        // attached. `page` only advances from an actual `fetch()` call,
        // so it stays the correct "have we ever paged through the
        // library" signal regardless of what `hydrate()` seeded in.
        if (store.page === 0) {
            void loadInitial();
        }
    },
    { immediate: true },
);

async function loadInitial(): Promise<void> {
    try {
        await store.fetch();
    } catch {
        loadError.value = true;
    }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function onSearchInput(value: string): void {
    searchInput.value = value;
    store.setSearch(value);

    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        void refreshSearch();
    }, 300);
}

async function refreshSearch(): Promise<void> {
    loadError.value = false;

    try {
        await store.fetch({ reset: true });
    } catch {
        loadError.value = true;
    }
}

async function loadMore(): Promise<void> {
    loadingMore.value = true;
    loadError.value = false;

    try {
        await store.fetch();
    } catch {
        loadError.value = true;
    } finally {
        loadingMore.value = false;
    }
}

function resolvedSelection(): MediaItem[] {
    return selection.value.map((id) => store.items.find((item) => item.id === id)).filter((item): item is MediaItem => Boolean(item));
}

const selectedImageCount = computed(() => resolvedSelection().filter((item) => item.mime.startsWith('image/')).length);
const selectedVideoCount = computed(() => resolvedSelection().filter((item) => item.mime.startsWith('video/')).length);

/**
 * Best-effort client-side limit sourced from THIS channel's own
 * `ProviderCapabilities` (never a shared constant), mirroring
 * `ValidationHints.vue`'s "always-on" media hint. This is a courtesy,
 * not the source of truth — `PostValidationService` (Task 28) is what
 * actually enforces it server-side — so a selected id whose `MediaItem`
 * this picker hasn't resolved yet (not on any page fetched so far)
 * simply isn't counted against the limit until it is.
 */
function limitReachedFor(item: MediaItem): boolean {
    if (!props.capabilities) {
        return false;
    }

    if (item.mime.startsWith('image/')) {
        return selectedImageCount.value >= props.capabilities.maxImages;
    }

    if (item.mime.startsWith('video/')) {
        return selectedVideoCount.value >= props.capabilities.maxVideos;
    }

    return false;
}

function toggle(id: string): void {
    const index = selection.value.indexOf(id);

    if (index !== -1) {
        selection.value.splice(index, 1);

        return;
    }

    const item = store.items.find((entry) => entry.id === id);

    if (item && limitReachedFor(item)) {
        return;
    }

    selection.value.push(id);
}

// A file dropped/imported directly inside the picker is added to the
// end of the selection right away — same append-only order rule as
// `toggle()` — rather than only landing in the library for the user to
// separately click.
function onUploaded(item: MediaItem): void {
    if (limitReachedFor(item)) {
        return;
    }

    selection.value.push(item.id);
}

function confirm(): void {
    emit('update:modelValue', [...selection.value]);
    emit('close');
}

function cancel(): void {
    emit('close');
}
</script>

<template>
    <Modal :open="open" :title="t('media.picker.title')" @close="cancel">
        <div class="flex max-h-[70vh] flex-col gap-3 overflow-y-auto">
            <MediaUploader @uploaded="onUploaded" />

            <Input
                :model-value="searchInput"
                type="search"
                :aria-label="t('media.search')"
                :placeholder="t('media.search')"
                @update:model-value="onSearchInput"
            />

            <Toast v-if="loadError" variant="danger" @dismiss="loadError = false">{{ t('media.loadError') }}</Toast>

            <p class="text-xs text-muted">{{ t('media.picker.selectedCount', { count: selection.length }) }}</p>

            <MediaGrid :items="store.items" selectable :selected-ids="selection" :empty-message="t('media.empty')" @toggle="toggle" />

            <Button v-if="store.hasMore" variant="ghost" type="button" :loading="loadingMore" @click="loadMore">
                {{ t('media.loadMore') }}
            </Button>

            <div class="flex justify-end gap-2 border-t border-border pt-3">
                <Button data-testid="media-picker-cancel" variant="ghost" type="button" @click="cancel">{{ t('ui.cancel') }}</Button>
                <Button data-testid="media-picker-confirm" variant="primary" type="button" @click="confirm">{{ t('media.picker.confirm') }}</Button>
            </div>
        </div>
    </Modal>
</template>
