<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '../ui/Button.vue';
import ContentTextarea from './ContentTextarea.vue';
import ValidationHints from './ValidationHints.vue';
import MediaPicker from '../media/MediaPicker.vue';
import { useMediaStore } from '../../stores/media';
import type { PostSegment, ProviderCapabilities } from './types';

/**
 * Edits one channel's thread — a linear chain of `PostSegment`s, each
 * replying to the previous one (never to the root; see the task-25
 * brief). Threads are inherently per-channel: `capabilities` always
 * comes from that one channel's own provider, so `supportsThreads` /
 * `maxThreadLength` / `maxCharacters` gate this exact instance's "+"
 * button and counters — never a value shared across channels.
 *
 * Task 29: each segment gets its own "attach media" trigger, opening a
 * single shared `MediaPicker` instance scoped to whichever segment's
 * index is in `pickerSegmentIndex` — never one `MediaPicker` per
 * segment, since only one can sensibly be open at a time and each is a
 * full modal with its own fetch/upload state. `capabilities` (this same
 * channel's own) is passed straight through, so the picker's
 * best-effort selection limit matches this thread's actual provider,
 * never a shared constant.
 */
const props = withDefaults(
    defineProps<{
        modelValue: PostSegment[];
        capabilities: ProviderCapabilities;
        /** Characters the signature this channel has selected will add to the LAST segment once submitted (see PostEditorModal.vue). */
        signatureLength?: number;
        label?: string;
        /** Server-side content errors (Task 19's `errors` map, one message per segment index). */
        errors?: Record<number, string[]>;
    }>(),
    {
        signatureLength: 0,
        label: undefined,
        errors: () => ({}),
    },
);

const emit = defineEmits<{ 'update:modelValue': [PostSegment[]] }>();

const { t } = useI18n();
const mediaStore = useMediaStore();

const pickerSegmentIndex = ref<number | null>(null);

function resolveMedia(id: string) {
    return mediaStore.items.find((item) => item.id === id);
}

/**
 * A still image can always be shown, thumbnail or not; a video has no
 * thumbnail until one is generated, and its own `url` is not an image,
 * so it falls through to the glyph beside this in the template.
 */
function chipPreviewSrc(id: string): string | null {
    const item = resolveMedia(id);

    if (!item) {
        return null;
    }

    return item.thumbnail_url ?? (item.mime.startsWith('video/') ? null : item.url);
}

function attachMedia(index: number): void {
    pickerSegmentIndex.value = index;
}

function removeMedia(index: number, mediaId: string): void {
    const next = props.modelValue.map((segment, i) =>
        i === index ? { ...segment, media_ids: segment.media_ids.filter((id) => id !== mediaId) } : segment,
    );
    emit('update:modelValue', next);
}

function onPickerConfirm(mediaIds: string[]): void {
    if (pickerSegmentIndex.value === null) {
        return;
    }

    const index = pickerSegmentIndex.value;
    const next = props.modelValue.map((segment, i) => (i === index ? { ...segment, media_ids: mediaIds } : segment));
    emit('update:modelValue', next);
    pickerSegmentIndex.value = null;
}

const activeSegmentMediaIds = computed(() =>
    pickerSegmentIndex.value !== null ? (props.modelValue[pickerSegmentIndex.value]?.media_ids ?? []) : [],
);

const canAddSegment = computed(
    () =>
        props.capabilities.supportsThreads &&
        (props.capabilities.maxThreadLength === null || props.modelValue.length < props.capabilities.maxThreadLength),
);

function updateSegment(index: number, content: string): void {
    const next = props.modelValue.map((segment, i) => (i === index ? { ...segment, content } : segment));
    emit('update:modelValue', next);
}

function addSegment(): void {
    if (!canAddSegment.value) {
        return;
    }

    emit('update:modelValue', [...props.modelValue, { content: '', media_ids: [] }]);
}

function removeSegment(index: number): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div v-for="(segment, index) in modelValue" :key="index" class="flex items-start gap-2">
            <div class="flex flex-1 flex-col gap-1.5">
                <ContentTextarea
                    :id="`thread-segment-${index}`"
                    :model-value="segment.content"
                    :limit="capabilities.maxCharacters"
                    :marks="capabilities.supportedMarks"
                    :emoji="true"
                    :extra-count="index === modelValue.length - 1 ? signatureLength : 0"
                    :label="index === 0 ? (label ? t('editor.thread.rootLabelFor', { name: label }) : t('editor.thread.rootLabel')) : t('editor.thread.replyLabel', { n: index })"
                    :error="errors[index]?.[0]"
                    :testid="`counter-${index}`"
                    @update:model-value="updateSegment(index, $event)"
                />

                <div class="flex flex-wrap items-center gap-1.5">
                    <div
                        v-for="mediaId in segment.media_ids"
                        :key="mediaId"
                        :data-testid="`media-chip-${mediaId}`"
                        :title="resolveMedia(mediaId)?.original_filename"
                        class="relative h-10 w-10 shrink-0 overflow-hidden rounded-xl border border-border bg-surface-2"
                    >
                        <!--
                            Final review, Minor: this was gated on
                            `thumbnail_url` alone, which is null for every
                            video, so an attached video rendered as an empty
                            box with only an × button. Same fallback shape
                            MediaTile.vue already uses: an image can fall
                            back to its own url, a video to a glyph and a
                            label.
                        -->
                        <img
                            v-if="chipPreviewSrc(mediaId)"
                            :src="chipPreviewSrc(mediaId)!"
                            :alt="resolveMedia(mediaId)?.original_filename"
                            class="h-full w-full object-cover"
                        />
                        <span v-else class="flex h-full w-full flex-col items-center justify-center text-[9px] leading-none text-muted">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path
                                    d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm9.555 5.168A1 1 0 0012 9v2a1 1 0 001.555.832l1.444-.964a1 1 0 000-1.664l-1.444-.963z"
                                />
                            </svg>
                            {{ t('media.video') }}
                        </span>
                        <button
                            type="button"
                            class="absolute right-0 top-0 flex h-4 w-4 items-center justify-center rounded-bl bg-surface text-[10px] text-muted hover:text-danger focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            :aria-label="t('editor.thread.removeMedia')"
                            @click="removeMedia(index, mediaId)"
                        >
                            &times;
                        </button>
                    </div>
                    <Button
                        :data-testid="`media-attach-${index}`"
                        variant="ghost"
                        type="button"
                        @click="attachMedia(index)"
                    >
                        {{ t('editor.thread.attachMedia') }}
                    </Button>
                </div>
            </div>
            <Button
                v-if="modelValue.length > 1"
                :data-testid="`thread-remove-${index}`"
                variant="ghost"
                type="button"
                class="mt-6"
                @click="removeSegment(index)"
            >
                {{ t('editor.thread.remove') }}
            </Button>
        </div>

        <div class="flex flex-col gap-1">
            <Button
                data-testid="thread-add"
                variant="secondary"
                type="button"
                class="self-start"
                :disabled="!canAddSegment"
                @click="addSegment"
            >
                {{ t('editor.thread.add') }}
            </Button>
            <p v-if="!capabilities.supportsThreads" class="text-xs text-muted">{{ t('editor.hints.threadUnsupported') }}</p>
            <p v-else-if="!canAddSegment" class="text-xs text-muted">
                {{ t('editor.hints.threadTooLong', { max: capabilities.maxThreadLength }) }}
            </p>
        </div>

        <ValidationHints
            :capabilities="capabilities"
            :thread-length="modelValue.length"
            :errors="errors[-1]"
            :content="modelValue.map((segment) => segment.content).join('\n')"
        />

        <MediaPicker
            :open="pickerSegmentIndex !== null"
            :model-value="activeSegmentMediaIds"
            :capabilities="capabilities"
            @update:model-value="onPickerConfirm"
            @close="pickerSegmentIndex = null"
        />
    </div>
</template>
