<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { TextMark } from '../../stores/integrations';
import type { PreviewSegment } from './types';

/**
 * A Bluesky post and its reply chain. BlueskyProvider's threading is a
 * genuine linked list — each segment replies to the one before it, not
 * to the root — so every reply is drawn nested under a rail rather than
 * as a flat run of messages. The circular avatar is Bluesky's own
 * convention.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));

/**
 * Which marks this network renders, mirroring its provider's
 * `supportedMarks` (app/Social/Providers). None: an AT Protocol post carries plain text, and this app builds
 * no facets.
 *
 * Living here is exactly right: `components/previews/` is the one place
 * on the frontend allowed to know which provider is which.
 */
const MARKS: TextMark[] = [];
</script>

<template>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
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
                <PreviewText class="text-sm text-text" :text="segment.content" :marks="MARKS" />
            </div>
        </div>
    </div>
</template>
