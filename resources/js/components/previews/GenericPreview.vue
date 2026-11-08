<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PreviewAvatar from './PreviewAvatar.vue';
import PreviewText from './PreviewText.vue';
import type { PreviewSegment } from './types';

/**
 * The registry's default (`resolvePreview()`'s fallback) for any
 * provider identifier without a dedicated component — most notably a
 * network added to the backend (a new `SocialProviderInterface`
 * implementation dropped into app/Social/Providers, registered in
 * ProviderRegistry) before Task 26-style frontend work adds its own
 * preview. Deliberately makes NO claim about how that network actually
 * renders posts: plain stacked text with an explicit "no
 * network-specific preview yet" notice, so nobody mistakes this generic
 * box for that network's real look.
 */
const props = defineProps<{ channelName: string; channelPicture: string | null; segments: PreviewSegment[] }>();

const { t } = useI18n();

const hasContent = computed(() => props.segments.some((segment) => segment.content.trim() !== ''));
</script>

<template>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
        <div class="flex items-center gap-2">
            <PreviewAvatar :name="channelName" :picture="channelPicture" shape="circle" />
            <p class="truncate text-sm font-semibold text-text">{{ channelName }}</p>
        </div>

        <p class="text-xs text-muted">{{ t('preview.generic.notice') }}</p>

        <p v-if="!hasContent" class="text-sm text-muted">{{ t('preview.empty') }}</p>

        <div v-else class="flex flex-col gap-2">
            <!--
                No `marks`: the fallback preview draws a network this
                build knows nothing about, so it cannot promise that any
                mark will render. Showing the markers as characters is
                the honest default -- the same choice the parser makes
                for a network that declares none.
            -->
            <PreviewText
                v-for="(segment, index) in segments"
                :key="index"
                class="text-sm text-text"
                :text="segment.content"
            />
        </div>
    </div>
</template>
