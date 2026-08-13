<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '../ui/Button.vue';
import ContentTextarea from './ContentTextarea.vue';
import ValidationHints from './ValidationHints.vue';
import type { PostSegment, ProviderCapabilities } from './types';

/**
 * Edits one channel's thread — a linear chain of `PostSegment`s, each
 * replying to the previous one (never to the root; see the task-25
 * brief). Threads are inherently per-channel: `capabilities` always
 * comes from that one channel's own provider, so `supportsThreads` /
 * `maxThreadLength` / `maxCharacters` gate this exact instance's "+"
 * button and counters — never a value shared across channels.
 */
const props = withDefaults(
    defineProps<{
        modelValue: PostSegment[];
        capabilities: ProviderCapabilities;
        /** Characters the signature this channel has selected will add to the LAST segment once submitted (see PostEditorModal.vue). */
        signatureLength?: number;
        label?: string;
        /** Server-side content errors (Task 19's `errors` map, one message per segment index). */
        errors?: Record<number, string[]>;
    }>(),
    {
        signatureLength: 0,
        label: undefined,
        errors: () => ({}),
    },
);

const emit = defineEmits<{ 'update:modelValue': [PostSegment[]] }>();

const { t } = useI18n();

const canAddSegment = computed(
    () =>
        props.capabilities.supportsThreads &&
        (props.capabilities.maxThreadLength === null || props.modelValue.length < props.capabilities.maxThreadLength),
);

function updateSegment(index: number, content: string): void {
    const next = props.modelValue.map((segment, i) => (i === index ? { ...segment, content } : segment));
    emit('update:modelValue', next);
}

function addSegment(): void {
    if (!canAddSegment.value) {
        return;
    }

    emit('update:modelValue', [...props.modelValue, { content: '', media_ids: [] }]);
}

function removeSegment(index: number): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div v-for="(segment, index) in modelValue" :key="index" class="flex items-start gap-2">
            <div class="flex-1">
                <ContentTextarea
                    :id="`thread-segment-${index}`"
                    :model-value="segment.content"
                    :limit="capabilities.maxCharacters"
                    :extra-count="index === modelValue.length - 1 ? signatureLength : 0"
                    :label="index === 0 ? (label ? t('editor.thread.rootLabelFor', { name: label }) : t('editor.thread.rootLabel')) : t('editor.thread.replyLabel', { n: index })"
                    :error="errors[index]?.[0]"
                    :testid="`counter-${index}`"
                    @update:model-value="updateSegment(index, $event)"
                />
            </div>
            <Button
                v-if="modelValue.length > 1"
                :data-testid="`thread-remove-${index}`"
                variant="ghost"
                type="button"
                class="mt-6"
                @click="removeSegment(index)"
            >
                {{ t('editor.thread.remove') }}
            </Button>
        </div>

        <div class="flex flex-col gap-1">
            <Button
                data-testid="thread-add"
                variant="secondary"
                type="button"
                class="self-start"
                :disabled="!canAddSegment"
                @click="addSegment"
            >
                {{ t('editor.thread.add') }}
            </Button>
            <p v-if="!capabilities.supportsThreads" class="text-xs text-muted">{{ t('editor.hints.threadUnsupported') }}</p>
            <p v-else-if="!canAddSegment" class="text-xs text-muted">
                {{ t('editor.hints.threadTooLong', { max: capabilities.maxThreadLength }) }}
            </p>
        </div>

        <ValidationHints :capabilities="capabilities" :thread-length="modelValue.length" :errors="errors[-1]" />
    </div>
</template>
