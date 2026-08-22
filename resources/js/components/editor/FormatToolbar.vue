<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '../ui/Input.vue';
import Button from '../ui/Button.vue';
import type { TextMark } from '../../stores/integrations';

/**
 * The format buttons for one channel's text field.
 *
 * `marks` comes verbatim from that channel's
 * `capabilities.supportedMarks` — the component never learns, and must
 * never learn, which network it is drawing for (see
 * resources/js/__tests__/providerIsolation.spec.ts, the frontend twin of
 * the backend's isolation guard). "This network prints [text](url)
 * literally" is expressed by the absence of LINK in the list, not by a
 * check on a provider id here.
 *
 * An empty list renders nothing at all rather than a row of disabled
 * buttons: a greyed-out bold button on a network that has no bold is a
 * promise the publish step cannot keep, and users read a disabled
 * control as "not yet", not as "never".
 *
 * The component only says WHICH markers to wrap the selection in; the
 * text surgery and the caret belong to whoever owns the textarea, via
 * `useCaretInsertion`.
 */
const props = defineProps<{ marks: TextMark[] }>();

const emit = defineEmits<{ wrap: [string, string] }>();

const { t } = useI18n();

/** The canonical Markdown subset — same markers the backend parses. */
const MARKERS: Record<Exclude<TextMark, 'LINK'>, [string, string]> = {
    BOLD: ['**', '**'],
    ITALIC: ['*', '*'],
    STRIKE: ['~~', '~~'],
    CODE: ['`', '`'],
};

/** Fixed order, so the row does not reshuffle between channels. */
const ORDER: TextMark[] = ['BOLD', 'ITALIC', 'STRIKE', 'CODE', 'LINK'];

const shown = computed(() => ORDER.filter((mark) => props.marks.includes(mark)));

const linkOpen = ref(false);
const linkUrl = ref('');

function apply(mark: TextMark): void {
    if (mark === 'LINK') {
        linkOpen.value = true;

        return;
    }

    emit('wrap', ...MARKERS[mark]);
}

function applyLink(): void {
    const url = linkUrl.value.trim();

    if (url === '') {
        return;
    }

    emit('wrap', '[', `](${url})`);
    linkUrl.value = '';
    linkOpen.value = false;
}

const GLYPHS: Record<TextMark, string> = {
    BOLD: 'B',
    ITALIC: 'I',
    STRIKE: 'S',
    CODE: '{ }',
    LINK: '🔗',
};

const STYLES: Record<TextMark, string> = {
    BOLD: 'font-bold',
    ITALIC: 'italic',
    STRIKE: 'line-through',
    CODE: 'font-mono text-xs',
    LINK: '',
};
</script>

<template>
    <div v-if="shown.length > 0" data-testid="format-toolbar" class="relative flex flex-wrap items-center gap-1">
        <button
            v-for="mark in shown"
            :key="mark"
            :data-testid="`format-${mark}`"
            type="button"
            :aria-label="t(`editor.format.${mark.toLowerCase()}`)"
            :title="t(`editor.format.${mark.toLowerCase()}`)"
            class="min-w-8 rounded-lg border border-border bg-surface px-2 py-1 text-sm text-muted hover:bg-surface-2 hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :class="STYLES[mark]"
            @click="apply(mark)"
        >{{ GLYPHS[mark] }}</button>

        <div
            v-if="linkOpen"
            class="absolute left-0 top-full z-20 mt-1 flex w-72 flex-col gap-2 rounded-xl border border-border bg-surface p-2 shadow-lg"
            @keydown.escape="linkOpen = false"
        >
            <Input
                v-model="linkUrl"
                data-testid="format-link-url"
                type="url"
                :label="t('editor.format.linkUrl')"
                placeholder="https://"
                @keydown.enter="applyLink"
            />
            <div class="flex items-center gap-2">
                <Button data-testid="format-link-apply" type="button" @click="applyLink">
                    {{ t('editor.format.linkApply') }}
                </Button>
                <Button variant="ghost" type="button" @click="linkOpen = false">
                    {{ t('editor.format.linkCancel') }}
                </Button>
            </div>
        </div>
    </div>
</template>
