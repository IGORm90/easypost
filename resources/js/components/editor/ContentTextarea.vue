<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmojiPicker from './EmojiPicker.vue';
import FormatToolbar from './FormatToolbar.vue';
import { useCaretInsertion } from '../../composables/useCaretInsertion';
import type { TextMark } from '../../stores/integrations';

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
 *
 * Block 3 hung the emoji picker and the format toolbar here rather than
 * beside this component in ThreadEditor, because both of them need the
 * one thing only this component has: the <textarea> element itself, and
 * with it the caret. `marks` is the channel's own
 * `capabilities.supportedMarks` passed straight through — an empty list
 * renders no toolbar at all.
 *
 * Markers count toward the limit, exactly as they do server-side
 * (PostValidationService counts the stored body, not the rendered one).
 * The count is therefore a little pessimistic for a network that strips
 * the markers into tags, and pessimistic is the right direction: the
 * editor may warn about a post the network would have accepted, never
 * the reverse.
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
        /** The target channel's supported marks; empty (the default) means no toolbar. */
        marks?: TextMark[];
        /** Whether to offer the emoji picker beside the field. */
        emoji?: boolean;
    }>(),
    {
        extraCount: 0,
        id: undefined,
        label: undefined,
        placeholder: undefined,
        rows: 4,
        error: undefined,
        testid: undefined,
        marks: () => [],
        emoji: false,
    },
);

const emit = defineEmits<{ 'update:modelValue': [string] }>();

const { t } = useI18n();

const count = computed(() => [...props.modelValue].length + props.extraCount);
const exceeded = computed(() => count.value > props.limit);
const over = computed(() => count.value - props.limit);

const field = ref<HTMLTextAreaElement | null>(null);

const { insertAtCaret, wrapSelection } = useCaretInsertion(
    field,
    () => props.modelValue,
    (value) => emit('update:modelValue', value),
);

function onInput(event: Event): void {
    emit('update:modelValue', (event.target as HTMLTextAreaElement).value);
}

/**
 * Bound to this field, never to window: the editor has one of these per
 * thread segment, and a global handler would format whichever segment
 * the code happened to reach first rather than the one being typed in.
 */
function onKeydown(event: KeyboardEvent): void {
    if (!(event.metaKey || event.ctrlKey)) {
        return;
    }

    const mark = { b: 'BOLD', i: 'ITALIC' }[event.key.toLowerCase()] as TextMark | undefined;

    if (!mark || !props.marks.includes(mark)) {
        return;
    }

    event.preventDefault();
    const marker = mark === 'BOLD' ? '**' : '*';
    wrapSelection(marker, marker);
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <label v-if="label" :for="id" class="text-sm font-medium text-text">{{ label }}</label>
        <div v-if="marks.length > 0 || emoji" class="flex flex-wrap items-center gap-1">
            <FormatToolbar :marks="marks" @wrap="wrapSelection" />
            <EmojiPicker v-if="emoji" @select="insertAtCaret" />
        </div>
        <textarea
            :id="id"
            ref="field"
            :value="modelValue"
            :placeholder="placeholder"
            :rows="rows"
            :aria-invalid="Boolean(error) || exceeded"
            class="resize-y rounded-xl border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted focus:outline-none focus:ring-2"
            :class="exceeded || error ? 'border-danger focus:ring-danger' : 'border-border focus:ring-primary'"
            @input="onInput"
            @keydown="onKeydown"
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
