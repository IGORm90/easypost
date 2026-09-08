<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { TextMark } from '../../stores/integrations';
import type { PreviewSegment } from './types';

/**
 * A Reddit submission followed by its comment replies — mirrors
 * RedditProvider::post() (app/Social/Providers/RedditProvider.php): the
 * first segment is the submission, every later segment is a comment
 * anchored to the one before it, rendered here as nested replies rather
 * than Telegram's stacked-post feed, because that IS how a Reddit thread
 * actually looks.
 *
 * PreviewSegment (./types.ts) carries only `content` — the subreddit and
 * title a post needs (RedditProvider::postSettings()) are per-post
 * settings collected by PostSettingsFields.vue, not threaded into the
 * preview segment type, so this preview shows the body text only and
 * does not fabricate a subreddit/title it was never given. That mirrors
 * how every other preview here still omits media (see types.ts's
 * docblock) rather than rendering a placeholder for data it doesn't
 * have.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));

/**
 * Mirrors RedditProvider::capabilities()'s full mark set: the canonical
 * post body already IS Reddit's own Markdown dialect, so nothing here
 * needs translating and every mark renders as typed.
 */
const MARKS: TextMark[] = ['BOLD', 'ITALIC', 'STRIKE', 'CODE', 'LINK'];
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4">
        <div class="flex items-center gap-2">
            <PreviewAvatar :name="channelName" :picture="channelPicture" shape="circle" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-text">{{ channelName }}</p>
                <p class="text-xs text-muted">{{ t('preview.reddit.kind') }}</p>
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
                <p v-if="index > 0" class="mb-1 text-xs text-muted">{{ t('preview.reddit.comment') }}</p>
                <PreviewText class="text-sm text-text" :text="segment.content" :marks="MARKS" />
            </div>
        </div>
    </div>
</template>
