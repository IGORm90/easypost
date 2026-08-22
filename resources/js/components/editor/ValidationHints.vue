<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ProviderCapabilities } from './types';

/**
 * Capability-driven explanations for one channel's thread — everything
 * here reads off `capabilities` (from `GET /api/providers`), never a
 * hardcoded per-provider name check. Two kinds of content:
 *
 *  - always-on informational hints (media limits) — low-key, muted text;
 *  - conditional warnings (thread unsupported/too long, server-side
 *    validation errors) — danger-coloured with an icon, never colour
 *    alone (color.md §3.3/§4).
 */
const props = withDefaults(
    defineProps<{
        capabilities: ProviderCapabilities;
        threadLength: number;
        errors?: string[];
        /** The channel's whole body (all segments joined) — only read to decide whether the "no formatting here" hint is worth showing. */
        content?: string;
    }>(),
    {
        errors: () => [],
        content: '',
    },
);

const { t } = useI18n();

const threadUnsupported = computed(() => props.threadLength > 1 && !props.capabilities.supportsThreads);
const threadTooLong = computed(
    () => props.capabilities.maxThreadLength !== null && props.threadLength > props.capabilities.maxThreadLength,
);

/**
 * A network with no formatting publishes markup characters verbatim
 * (Block 3): `**bold**` reaches the reader with its asterisks. That is
 * deliberate — refusing to save content because it "looks like markup"
 * would reject `2*3*4` and every code snippet — so this is a hint, not
 * an error, and it only appears once the body actually contains
 * something that would have been markup elsewhere.
 */
const marksUnsupported = computed(
    () => props.capabilities.supportedMarks.length === 0 && /(\*\*|~~|`|\[[^\]]+\]\()/.test(props.content),
);
</script>

<template>
    <div class="flex flex-col gap-1.5 text-xs">
        <p
            v-if="threadUnsupported"
            data-testid="hint-thread-unsupported"
            class="flex items-center gap-1.5 text-danger"
        >
            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M8.257 3.099c.765-1.36 2.72-1.36 3.486 0l6.516 11.59c.75 1.334-.213 2.98-1.743 2.98H3.483c-1.53 0-2.492-1.646-1.743-2.98l6.517-11.59zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                    clip-rule="evenodd"
                />
            </svg>
            {{ t('editor.hints.threadUnsupported') }}
        </p>

        <p v-if="threadTooLong" class="flex items-center gap-1.5 text-danger">
            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M8.257 3.099c.765-1.36 2.72-1.36 3.486 0l6.516 11.59c.75 1.334-.213 2.98-1.743 2.98H3.483c-1.53 0-2.492-1.646-1.743-2.98l6.517-11.59zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                    clip-rule="evenodd"
                />
            </svg>
            {{ t('editor.hints.threadTooLong', { max: capabilities.maxThreadLength }) }}
        </p>

        <p v-for="(message, index) in errors" :key="index" class="flex items-center gap-1.5 text-danger">
            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M8.257 3.099c.765-1.36 2.72-1.36 3.486 0l6.516 11.59c.75 1.334-.213 2.98-1.743 2.98H3.483c-1.53 0-2.492-1.646-1.743-2.98l6.517-11.59zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                    clip-rule="evenodd"
                />
            </svg>
            {{ message }}
        </p>

        <p v-if="marksUnsupported" data-testid="hint-marks-unsupported" class="text-muted">
            {{ t('editor.hints.marksUnsupported') }}
        </p>

        <p class="text-muted">
            {{
                t('editor.hints.mediaLimits', {
                    images: capabilities.maxImages,
                    videos: capabilities.maxVideos,
                })
            }}
            <template v-if="capabilities.requiresAltText"> {{ t('editor.hints.altTextRequired') }}</template>
        </p>
    </div>
</template>
