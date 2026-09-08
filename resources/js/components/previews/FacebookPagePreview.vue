<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { TextMark } from '../../stores/integrations';
import type { PreviewSegment } from './types';

/**
 * A single Facebook Page post. FacebookPageProvider::capabilities()
 * declares `supportsThreads: false` and `maxThreadLength: 1`, so unlike
 * Telegram's stacked feed or Bluesky/Reddit's nested replies, there is
 * never more than one segment to draw here — this still loops over
 * `segments` defensively (same pattern as every other preview here)
 * rather than hard-coding `segments[0]`, so a stray extra segment fails
 * loudly in PostValidationService rather than silently vanishing from
 * the preview.
 *
 * `rounded` avatar shape, not `circle`: a Facebook Page's own profile
 * picture is squared with rounded corners, distinct from the circular
 * convention Telegram/Bluesky/Reddit/Instagram/Threads all use here.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));

/**
 * Mirrors FacebookPageProvider::capabilities()'s empty mark set: a Page
 * post body is delivered to Graph as plain text, nothing to render as
 * typed.
 */
const MARKS: TextMark[] = [];
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4">
        <div class="flex items-center gap-2">
            <PreviewAvatar :name="channelName" :picture="channelPicture" shape="rounded" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-text">{{ channelName }}</p>
                <p class="text-xs text-muted">{{ t('preview.facebook-page.kind') }}</p>
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
