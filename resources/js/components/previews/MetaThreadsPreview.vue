<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { TextMark } from '../../stores/integrations';
import type { PreviewSegment } from './types';

/**
 * A Threads post and its native reply chain. ThreadsProvider's
 * threading is a genuine linked list — each segment replies to the one
 * before it via `reply_to_id`, not to the root — so, like
 * BlueskyPreview.vue, every reply nests under a rail rather than
 * stacking as flat consecutive posts the way TelegramPreview.vue draws
 * a channel feed.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));

/**
 * Mirrors ThreadsProvider::capabilities()'s empty mark set: a Threads
 * post is delivered to Graph as plain text.
 */
const MARKS: TextMark[] = [];
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4">
        <div class="flex items-center gap-2">
            <PreviewAvatar :name="channelName" :picture="channelPicture" shape="circle" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-text">{{ channelName }}</p>
                <p class="text-xs text-muted">{{ t('preview.meta-threads.kind') }}</p>
            </div>
        </div>

        <p v-if="!hasContent" class="text-sm text-muted">{{ t('preview.empty') }}</p>

        <div v-else class="flex flex-col gap-3">
            <div v-for="(segment, index) in segments" :key="index" :class="index > 0 ? 'ml-4 border-l-2 border-border pl-3' : ''">
                <p v-if="index > 0" class="mb-1 text-xs text-muted">{{ t('preview.meta-threads.reply') }}</p>
                <PreviewText class="text-sm text-text" :text="segment.content" :marks="MARKS" />
            </div>
        </div>
    </div>
</template>
