<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { MediaItem } from '../../stores/media';

/**
 * One media library tile — shared by `MediaGrid.vue` for both the
 * library page (`deletable`) and `MediaPicker.vue` (`selectable`,
 * shows `order`).
 *
 * Reflow guard (Task 29 brief: "the grid must not reflow as images
 * load"): `width`/`height` come straight off `MediaResource` (Task 28)
 * and are applied as a CSS `aspect-ratio` on the tile's frame BEFORE
 * the `<img>` has loaded anything — never derived from the loaded
 * image's own natural size, which would arrive too late to prevent the
 * reflow it's meant to avoid. Both are `null` for video and for any
 * image `getimagesize()` couldn't parse (MediaResource's docblock);
 * those tiles fall back to the frame's own fixed 1:1 (`aspect-square`)
 * box instead of collapsing to zero height.
 *
 * `original_filename` is user input that reaches the DOM (a caller can
 * rename nothing server-side, but the upload form filename is
 * attacker-controlled) — it is only ever placed through `{{ }}`
 * interpolation/`:alt`/`:title` here, which Vue escapes; never `v-html`.
 *
 * A `pending` (still-uploading) tile can be neither selected nor
 * deleted — see `canSelect`/`canDelete` below.
 */
const props = withDefaults(
    defineProps<{
        item: MediaItem;
        selectable?: boolean;
        selected?: boolean;
        /** 1-based position in the current selection, or `null` when not selected — shown as a badge. */
        order?: number | null;
        deletable?: boolean;
    }>(),
    {
        selectable: false,
        selected: false,
        order: null,
        deletable: false,
    },
);

const emit = defineEmits<{ toggle: []; delete: [] }>();

const { t } = useI18n();

const isVideo = computed(() => props.item.mime.startsWith('video/'));
const previewSrc = computed(() => props.item.thumbnail_url ?? (isVideo.value ? null : props.item.url));

const aspectStyle = computed(() => {
    if (props.item.width && props.item.height) {
        return { aspectRatio: `${props.item.width} / ${props.item.height}` };
    }

    return {};
});

// A `pending` placeholder's id (`pending-<uuid>`) is a client-only
// stand-in the server has never heard of — review finding 3: letting it
// into a selection sends a dead id to `POST/PUT /api/posts`, which 422s
// the WHOLE post over `Rule::exists('media','id')`, and finding
// "cheap and real": `DELETE /media/pending-...` can only ever fail.
// Both the selectable toggle and the delete control are disabled/hidden
// below while `item.pending`, so neither path can ever reach a
// placeholder id.
const canSelect = computed(() => props.selectable && !props.item.pending);
const canDelete = computed(() => props.deletable && !props.item.pending);

function onClick(): void {
    if (canSelect.value) {
        emit('toggle');
    }
}
</script>

<template>
    <figure class="m-0 flex flex-col overflow-hidden rounded-lg border bg-surface" :class="selected ? 'border-primary' : 'border-border'">
        <button
            type="button"
            class="relative flex aspect-square w-full items-center justify-center overflow-hidden bg-surface-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :style="aspectStyle"
            :aria-pressed="selectable ? selected : undefined"
            :disabled="!canSelect"
            @click="onClick"
        >
            <img v-if="previewSrc" :src="previewSrc" :alt="item.original_filename" loading="lazy" class="h-full w-full object-cover" />
            <span v-else class="flex flex-col items-center gap-1 text-xs text-muted">
                <svg class="h-8 w-8" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm9.555 5.168A1 1 0 0012 9v2a1 1 0 001.555.832l1.444-.964a1 1 0 000-1.664l-1.444-.963z"
                    />
                </svg>
                {{ t('media.video') }}
            </span>

            <span v-if="item.pending" class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-surface px-2 text-xs text-muted">
                {{ t('media.uploading') }}
                <span v-if="item.progress !== undefined" class="h-1.5 w-3/4 overflow-hidden rounded-xl bg-surface-2">
                    <span class="block h-full rounded-xl bg-primary transition-all" :style="{ width: `${item.progress}%` }" />
                </span>
            </span>

            <span
                v-if="selectable && selected && order !== null"
                class="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-[11px] font-semibold text-on-primary"
            >
                {{ order }}
            </span>
        </button>

        <figcaption class="flex items-center gap-1.5 px-2.5 py-2">
            <span class="min-w-0 flex-1 truncate text-xs font-medium text-text" :title="item.original_filename">{{ item.original_filename }}</span>
            <button
                v-if="canDelete"
                type="button"
                class="shrink-0 rounded-xl p-1.5 text-muted hover:bg-danger-soft hover:text-danger focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                :aria-label="t('media.delete')"
                @click="emit('delete')"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13" /></svg>
            </button>
        </figcaption>
    </figure>
</template>
