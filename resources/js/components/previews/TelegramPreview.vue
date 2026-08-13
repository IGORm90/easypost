<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { PreviewSegment } from './types';

/**
 * A Telegram channel post. `capabilities.supportsThreads` is true for
 * Telegram (TelegramProvider::capabilities()), but a channel is a feed
 * of stacked posts, not a reply-chain UI the way Mastodon/Bluesky render
 * one — so each segment is shown as its own consecutive post in the
 * feed, connected by a thin rail rather than drawn as nested replies.
 * That IS the real Telegram rendering, not a simplification of it.
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
                <p class="text-xs text-muted">{{ t('preview.telegram.kind') }}</p>
            </div>
        </div>

        <p v-if="!hasContent" class="text-sm text-muted">{{ t('preview.empty') }}</p>

        <div v-else class="flex flex-col gap-2">
            <div
                v-for="(segment, index) in segments"
                :key="index"
                class="rounded-xl bg-surface-2 px-3 py-2"
                :class="index > 0 ? 'ml-4 border-l-2 border-border' : ''"
            >
                <PreviewText class="text-sm text-text" :text="segment.content" />
            </div>
        </div>
    </div>
</template>
