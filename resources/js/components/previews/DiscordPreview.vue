<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { TextMark } from '../../stores/integrations';
import type { PreviewSegment } from './types';

/**
 * A Discord webhook message. DiscordProvider::capabilities() sets
 * `supportsThreads: false` — a webhook has no reply/message_reference
 * support (see that class's docblock), so the editor's UI already
 * blocks adding a second segment for a Discord channel. If more than
 * one segment still arrives here (e.g. content loaded from a group that
 * was composed before this constraint, or edited server-side), each one
 * is rendered as its own, visually UNLINKED message — repeating the
 * avatar/name the way Discord's own client does for separate messages —
 * with an explicit note that they publish as separate, unrelated
 * messages rather than a thread.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));

/**
 * Which marks this network renders, mirroring its provider's
 * `supportedMarks` (app/Social/Providers). No LINK: Discord prints [text](url) verbatim in a normal message,
 * so the preview has to print it verbatim too.
 *
 * Living here is exactly right: `components/previews/` is the one place
 * on the frontend allowed to know which provider is which.
 */
const MARKS: TextMark[] = ['BOLD', 'ITALIC', 'STRIKE', 'CODE'];
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface-2 p-4">
        <p v-if="!hasContent" class="text-sm text-muted">{{ t('preview.empty') }}</p>

        <div v-else class="flex flex-col gap-4">
            <div v-for="(segment, index) in segments" :key="index" class="flex items-start gap-2">
                <PreviewAvatar :name="channelName" :picture="channelPicture" shape="rounded" />
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2">
                        <p class="truncate text-sm font-semibold text-text">{{ channelName }}</p>
                        <span class="rounded bg-surface px-1 py-0.5 text-[10px] font-medium text-muted">{{ t('preview.discord.kind') }}</span>
                    </div>
                    <PreviewText class="text-sm text-text" :text="segment.content" :marks="MARKS" />
                </div>
            </div>

            <p v-if="segments.length > 1" class="text-xs text-muted">{{ t('preview.discord.unlinked') }}</p>
        </div>
    </div>
</template>
