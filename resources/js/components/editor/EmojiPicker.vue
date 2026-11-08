<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '../ui/Input.vue';
import { EMOJI_CATEGORIES, searchEmoji, type Emoji } from './emoji';

/**
 * A trigger button plus a popover of emoji, grouped by category, with a
 * search box that matches English and Russian keywords alike.
 *
 * No emoji library: the popular ones ship hundreds of kilobytes and
 * their own stylesheets, whose hex literals fail palette.spec.ts. The
 * dataset in `emoji.ts` covers what people reach for in a social post.
 *
 * This component only reports which character was chosen. Putting it
 * into the text — at the caret, not at the end — belongs to whoever owns
 * the textarea, via `useCaretInsertion`.
 *
 * Keyboard: the grid is a roving-tabindex grid (one cell in the tab
 * order, arrows move between cells), Escape closes and returns focus to
 * the trigger. A picker that swallows focus on close leaves a keyboard
 * user starting their next Tab from the top of the document.
 */
const emit = defineEmits<{ select: [string] }>();

const { t } = useI18n();

const open = ref(false);
const query = ref('');
const trigger = ref<HTMLButtonElement | null>(null);
const grid = ref<HTMLElement | null>(null);

/** Columns per row — the arrow-key geometry has to agree with the CSS grid. */
const COLUMNS = 8;

const searching = computed(() => query.value.trim() !== '');
const results = computed(() => searchEmoji(query.value));

/** Flat, in visual order: what the arrow keys walk. */
const visible = computed<Emoji[]>(() =>
    searching.value ? results.value : EMOJI_CATEGORIES.flatMap((category) => category.emoji),
);

function toggle(): void {
    open.value = !open.value;

    if (!open.value) {
        query.value = '';
    }
}

function close(): void {
    open.value = false;
    query.value = '';
    void nextTick(() => trigger.value?.focus());
}

function choose(emoji: Emoji): void {
    emit('select', emoji.char);
    close();
}

function indexOfChar(char: string): number {
    return visible.value.findIndex((emoji) => emoji.char === char);
}

function focusCell(index: number): void {
    const cells = grid.value?.querySelectorAll<HTMLButtonElement>('[data-testid^="emoji-cell-"]');
    cells?.[index]?.focus();
}

function onGridKeydown(event: KeyboardEvent, char: string): void {
    const deltas: Record<string, number> = {
        ArrowRight: 1,
        ArrowLeft: -1,
        ArrowDown: COLUMNS,
        ArrowUp: -COLUMNS,
    };
    const delta = deltas[event.key];

    if (delta === undefined) {
        return;
    }

    event.preventDefault();
    const next = indexOfChar(char) + delta;

    if (next >= 0 && next < visible.value.length) {
        focusCell(next);
    }
}
</script>

<template>
    <div class="relative">
        <button
            ref="trigger"
            data-testid="emoji-trigger"
            type="button"
            :aria-label="t('editor.emoji.open')"
            :aria-expanded="open"
            aria-haspopup="dialog"
            class="rounded-lg border border-border bg-surface px-2 py-1 text-sm text-muted hover:bg-surface-2 hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            @click="toggle"
        >
            🙂
        </button>

        <div
            v-if="open"
            data-testid="emoji-panel"
            role="dialog"
            :aria-label="t('editor.emoji.open')"
            class="absolute left-0 z-20 mt-1 flex w-72 flex-col gap-2 rounded-lg border border-border bg-surface p-2 shadow-lg"
            @keydown.escape="close"
        >
            <Input
                v-model="query"
                data-testid="emoji-search"
                type="search"
                :label="t('editor.emoji.search')"
            />

            <div ref="grid" data-testid="emoji-grid" role="grid" class="max-h-56 overflow-y-auto">
                <template v-if="searching">
                    <div class="grid grid-cols-8 gap-0.5" role="row">
                        <button
                            v-for="(emoji, index) in results"
                            :key="emoji.char"
                            :data-testid="`emoji-cell-${emoji.char}`"
                            type="button"
                            role="gridcell"
                            :tabindex="index === 0 ? 0 : -1"
                            :title="emoji.en"
                            class="rounded-lg p-1 text-lg hover:bg-surface-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            @click="choose(emoji)"
                            @keydown="onGridKeydown($event, emoji.char)"
                        >{{ emoji.char }}</button>
                    </div>
                    <p v-if="results.length === 0" data-testid="emoji-empty" class="px-1 py-3 text-center text-xs text-muted">
                        {{ t('editor.emoji.empty') }}
                    </p>
                </template>

                <template v-else>
                    <section v-for="category in EMOJI_CATEGORIES" :key="category.key" class="mb-1">
                        <h3 class="px-1 py-0.5 text-xs font-medium text-muted">
                            {{ t(`editor.emoji.categories.${category.key}`) }}
                        </h3>
                        <div class="grid grid-cols-8 gap-0.5" role="row">
                            <button
                                v-for="emoji in category.emoji"
                                :key="emoji.char"
                                :data-testid="`emoji-cell-${emoji.char}`"
                                type="button"
                                role="gridcell"
                                :tabindex="emoji.char === EMOJI_CATEGORIES[0].emoji[0].char ? 0 : -1"
                                :title="emoji.en"
                                class="rounded-lg p-1 text-lg hover:bg-surface-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                @click="choose(emoji)"
                                @keydown="onGridKeydown($event, emoji.char)"
                            >{{ emoji.char }}</button>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </div>
</template>
