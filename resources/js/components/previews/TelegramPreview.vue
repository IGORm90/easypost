<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { TextMark } from '../../stores/integrations';
import type { PreviewSegment } from './types';

/**
 * A Telegram channel post. `capabilities.supportsThreads` is true for
 * Telegram (TelegramProvider::capabilities()), but a channel is a feed
 * of stacked posts, not a reply-chain UI the way Bluesky renders
 * one — so each segment is shown as its own consecutive post in the
 * feed, connected by a thin rail rather than drawn as nested replies.
 * That IS the real Telegram rendering, not a simplification of it.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));

/**
 * Which marks this network renders, mirroring its provider's
 * `supportedMarks` (app/Social/Providers). Everything, because a Telegram message is published with
 * parse_mode=HTML and its provider renders all five.
 *
 * Living here is exactly right: `components/previews/` is the one place
 * on the frontend allowed to know which provider is which.
 */
const MARKS: TextMark[] = ['BOLD', 'ITALIC', 'STRIKE', 'CODE', 'LINK'];
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
                <PreviewText class="text-sm text-text" :text="segment.content" :marks="MARKS" />
            </div>
        </div>
    </div>
</template>
