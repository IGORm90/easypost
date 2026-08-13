<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { PreviewSegment } from './types';

/**
 * A Slack message and its thread. SlackProvider's docblock is explicit
 * that Slack threads are FLAT, not a linked list: every reply's
 * `thread_ts` points at the THREAD ROOT (segment 0), never at the
 * previous reply. So — unlike Mastodon/Bluesky, where segment N replies
 * to segment N-1 — every segment after the first is rendered as a
 * direct reply to the root message, all at the same indent, under a
 * single "N replies in thread" summary. Nesting them under each other
 * (as MastodonPreview/BlueskyPreview do) would misrepresent what Slack
 * actually publishes.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));
const root = computed(() => props.segments[0]);
const replies = computed(() => props.segments.slice(1));
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4">
        <div class="flex items-center gap-2">
            <PreviewAvatar :name="channelName" :picture="channelPicture" shape="rounded" />
            <p class="truncate text-sm font-semibold text-text">{{ channelName }}</p>
        </div>

        <p v-if="!hasContent" class="text-sm text-muted">{{ t('preview.empty') }}</p>

        <template v-else>
            <PreviewText class="text-sm text-text" :text="root.content" />

            <div v-if="replies.length > 0" class="ml-4 flex flex-col gap-2 border-l-2 border-border pl-3">
                <p class="text-xs font-medium text-muted">{{ t('preview.slack.thread') }} · {{ t('preview.slack.replies', { n: replies.length }) }}</p>
                <div v-for="(reply, index) in replies" :key="index" class="flex items-start gap-2">
                    <PreviewAvatar :name="channelName" :picture="channelPicture" shape="rounded" />
                    <PreviewText class="text-sm text-text" :text="reply.content" />
                </div>
            </div>
        </template>
    </div>
</template>
