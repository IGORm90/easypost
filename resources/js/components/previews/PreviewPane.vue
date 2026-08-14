<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { resolvePreview } from './registry';
import type { PreviewSegment } from './types';

/**
 * The seam PostEditorModal.vue (Task 25) left for Task 26: wraps a
 * channel's live draft in an approximation of how that network renders
 * a post. This component — and everything above it — is where the
 * "no `v-if="provider === 'telegram'"` outside the registry" rule
 * lives: `component` below is the ONLY provider-identity-aware
 * expression here, and it delegates entirely to `resolvePreview()`
 * rather than branching itself.
 */
const props = defineProps<{
    providerIdentifier: string;
    channelName: string;
    channelPicture: string | null;
    segments: PreviewSegment[];
}>();

const { t } = useI18n();

const component = computed(() => resolvePreview(props.providerIdentifier));
</script>

<template>
    <div class="flex flex-col gap-1">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-muted">{{ t('preview.title') }}</span>
            <span class="text-xs text-muted">{{ t('preview.approximation') }}</span>
        </div>
        <component
            :is="component"
            :channel-name="channelName"
            :channel-picture="channelPicture"
            :segments="segments"
        />
    </div>
</template>
