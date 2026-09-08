<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { TextMark } from '../../stores/integrations';
import type { PreviewSegment } from './types';

/**
 * A single Instagram post (image/video + caption). InstagramProvider::
 * capabilities() declares `supportsThreads: false` and
 * `maxThreadLength: 1` — this network never carries a chain, so there
 * is only ever one segment to draw; the loop below still stays
 * defensive rather than assuming `segments[0]`, same as
 * FacebookPagePreview.vue.
 *
 * This preview cannot show the actual photo/video: PreviewSegment
 * (./types.ts) is text-only until Tasks 27-29's media picker exists
 * (see that file's docblock) — `requiresMedia: true` on this network
 * means a real post never has empty content here, but the preview
 * itself has no way to know that without a media field, so `hasContent`
 * still only checks caption text, exactly like every other preview.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));

/**
 * Mirrors InstagramProvider::capabilities()'s empty mark set: a caption
 * is delivered to Graph as plain text.
 */
const MARKS: TextMark[] = [];
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4">
        <div class="flex items-center gap-2">
            <PreviewAvatar :name="channelName" :picture="channelPicture" shape="circle" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-text">{{ channelName }}</p>
                <p class="text-xs text-muted">{{ t('preview.instagram.kind') }}</p>
            </div>
        </div>

        <p v-if="!hasContent" class="text-sm text-muted">{{ t('preview.empty') }}</p>

        <div v-else class="flex flex-col gap-2">
            <div v-for="(segment, index) in segments" :key="index" class="rounded-xl bg-surface-2 px-3 py-2">
                <PreviewText class="text-sm text-text" :text="segment.content" :marks="MARKS" />
            </div>
        </div>
    </div>
</template>
