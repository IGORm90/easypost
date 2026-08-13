<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * A single segment's text input plus its live character counter. The
 * counter is driven entirely by `limit` (a provider's own
 * `capabilities.maxCharacters`, passed in by the caller — never a
 * hardcoded constant here) and `extraCount` (signature characters this
 * segment will carry once submitted — see ThreadEditor.vue). Counting
 * uses code points (`[...string].length`), matching
 * `PostValidationService::validate()`'s `mb_strlen($content, 'UTF-8')`
 * server-side rather than JS's UTF-16-code-unit `.length`, so Cyrillic
 * and most emoji count the same on both sides of the wire.
 *
 * Exceeding the limit is never colour-only (color.md §3.3/§4): the
 * counter also switches to a warning icon and an explicit "over by N"
 * text.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        limit: number;
        extraCount?: number;
        id?: string;
        label?: string;
        placeholder?: string;
        rows?: number;
        error?: string;
        testid?: string;
    }>(),
    {
        extraCount: 0,
        id: undefined,
        label: undefined,
        placeholder: undefined,
        rows: 4,
        error: undefined,
        testid: undefined,
    },
);

const emit = defineEmits<{ 'update:modelValue': [string] }>();

const { t } = useI18n();

const count = computed(() => [...props.modelValue].length + props.extraCount);
const exceeded = computed(() => count.value > props.limit);
const over = computed(() => count.value - props.limit);

function onInput(event: Event): void {
    emit('update:modelValue', (event.target as HTMLTextAreaElement).value);
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <label v-if="label" :for="id" class="text-sm font-medium text-text">{{ label }}</label>
        <textarea
            :id="id"
            :value="modelValue"
            :placeholder="placeholder"
            :rows="rows"
            :aria-invalid="Boolean(error) || exceeded"
            class="resize-y rounded-xl border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted focus:outline-none focus:ring-2"
            :class="exceeded || error ? 'border-danger focus:ring-danger' : 'border-border focus:ring-primary'"
            @input="onInput"
        />
        <div class="flex flex-wrap items-center gap-2 text-xs" :class="exceeded ? 'text-danger' : 'text-muted'" :data-testid="testid">
            <svg v-if="exceeded" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M8.257 3.099c.765-1.36 2.72-1.36 3.486 0l6.516 11.59c.75 1.334-.213 2.98-1.743 2.98H3.483c-1.53 0-2.492-1.646-1.743-2.98l6.517-11.59zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                    clip-rule="evenodd"
                />
            </svg>
            <span>{{ count }} / {{ limit }}</span>
            <span v-if="exceeded">{{ t('editor.counter.over', { n: over }) }}</span>
        </div>
        <p v-if="error" class="text-sm text-danger">{{ error }}</p>
    </div>
</template>
