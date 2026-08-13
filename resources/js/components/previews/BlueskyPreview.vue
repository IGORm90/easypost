<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { PreviewSegment } from './types';

/**
 * A Bluesky post and its reply chain. Like Mastodon (and unlike
 * Telegram/Slack), BlueskyProvider's threading is a genuine linked
 * list — each segment replies to the one before it — so it gets the
 * same nested-under-a-rail treatment as MastodonPreview, just with
 * Bluesky's own terminology ("Reply" rather than "toot") and a circular
 * avatar (Bluesky's own convention) instead of Mastodon's slightly
 * squarer one.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4">
        <div class="flex items-center gap-2">
            <PreviewAvatar :name="channelName" :picture="channelPicture" shape="circle" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-text">{{ channelName }}</p>
                <p class="text-xs text-muted">{{ t('preview.bluesky.kind') }}</p>
            </div>
        </div>

        <p v-if="!hasContent" class="text-sm text-muted">{{ t('preview.empty') }}</p>

        <div v-else class="flex flex-col gap-3">
            <div v-for="(segment, index) in segments" :key="index" :class="index > 0 ? 'ml-4 border-l-2 border-border pl-3' : ''">
                <p v-if="index > 0" class="mb-1 text-xs text-muted">{{ t('preview.bluesky.reply') }}</p>
                <PreviewText class="text-sm text-text" :text="segment.content" />
            </div>
        </div>
    </div>
</template>
