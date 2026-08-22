<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { TextMark } from '../../stores/integrations';
import type { PreviewSegment } from './types';

/**
 * A Mastodon toot and its reply chain. Unlike Telegram's flat channel
 * feed, MastodonProvider's threading is a genuine linked list — each
 * segment replies to the one before it — so segments after the first
 * are rendered nested under a connecting rail with an explicit "replying
 * to the previous post" label, matching that reply relationship rather
 * than just restacking them as independent posts.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));

/**
 * Which marks this network renders, mirroring its provider's
 * `supportedMarks` (app/Social/Providers). None: a status is submitted as plain text, so markers reach the
 * reader as characters.
 *
 * Living here is exactly right: `components/previews/` is the one place
 * on the frontend allowed to know which provider is which.
 */
const MARKS: TextMark[] = [];
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4">
        <div class="flex items-center gap-2">
            <PreviewAvatar :name="channelName" :picture="channelPicture" shape="rounded" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-text">{{ channelName }}</p>
                <p class="text-xs text-muted">{{ t('preview.mastodon.kind') }}</p>
            </div>
        </div>

        <p v-if="!hasContent" class="text-sm text-muted">{{ t('preview.empty') }}</p>

        <div v-else class="flex flex-col gap-3">
            <div v-for="(segment, index) in segments" :key="index" :class="index > 0 ? 'ml-4 border-l-2 border-border pl-3' : ''">
                <p v-if="index > 0" class="mb-1 text-xs text-muted">{{ t('preview.mastodon.replyingToPrevious') }}</p>
                <PreviewText class="text-sm text-text" :text="segment.content" :marks="MARKS" />
            </div>
        </div>
    </div>
</template>
