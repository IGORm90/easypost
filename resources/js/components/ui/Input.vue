<script setup lang="ts">
import { computed, useId } from 'vue';

type InputType = 'text' | 'email' | 'password' | 'search' | 'number' | 'url';

// Attrs like `autocomplete`/`required`/`maxlength` a form page passes must
// land on the actual <input>, not on this component's wrapping <div> (the
// template root) — Vue's default fallthrough would otherwise put them
// there, where a native "required" or "autocomplete" is a silent no-op.
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        type?: InputType;
        label?: string;
        error?: string;
        placeholder?: string;
        disabled?: boolean;
        id?: string;
    }>(),
    {
        modelValue: '',
        type: 'text',
        label: undefined,
        error: undefined,
        placeholder: undefined,
        disabled: false,
        id: undefined,
    },
);

defineEmits<{ 'update:modelValue': [string] }>();

/**
 * Final review, Important 11: `id` was optional but the template bound
 * `<label :for="id">`/`<input :id="id">` unconditionally, so the four
 * call sites that pass no `id` rendered both as `undefined` — a label
 * pointing at nothing. Falling back to a generated id makes the
 * association hold for every caller without any of them changing, and
 * an explicitly passed `id` still wins.
 */
const generatedId = useId();
const inputId = computed(() => props.id ?? generatedId);
/**
 * `aria-invalid="true"` with nothing to read out told a screen reader
 * only that the field was wrong, never why; this points it at the very
 * <p> that is already on screen.
 */
const errorId = computed(() => `${inputId.value}-error`);
const describedBy = computed(() => (props.error ? errorId.value : undefined));
</script>

<template>
    <div class="flex flex-col gap-1">
        <label v-if="label" :for="inputId" class="text-sm font-medium text-text">{{ label }}</label>
        <input
            :id="inputId"
            v-bind="$attrs"
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-invalid="Boolean(error)"
            :aria-describedby="describedBy"
            class="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:bg-surface-2 disabled:text-muted"
            :class="error ? 'border-danger focus:ring-danger' : ''"
            @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        />
        <p v-if="error" :id="errorId" class="flex items-center gap-1 text-sm text-danger">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M8.257 3.099c.765-1.36 2.72-1.36 3.486 0l6.516 11.59c.75 1.334-.213 2.98-1.743 2.98H3.483c-1.53 0-2.492-1.646-1.743-2.98l6.517-11.59zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                    clip-rule="evenodd"
                />
            </svg>
            {{ error }}
        </p>
    </div>
</template>
