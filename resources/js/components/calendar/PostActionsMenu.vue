<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

/**
 * The per-post actions menu. Purely presentational: it is handed a list
 * of items and emits the key of the one chosen — it knows nothing about
 * the store, about what "share" means, or about which items a given post
 * should offer. That decision lives in `CalendarEvent.vue` (and, later,
 * in the list view of Block 2), so both surfaces can offer different
 * sets without this component growing branches.
 *
 * It replaced a bare `×` button on the card. The card itself is a click
 * target that opens the editor (see CalendarGrid.vue's `eventClick`), so
 * every interaction here calls `stopPropagation()` — otherwise choosing
 * "Share" would also open the editor behind the modal that Share opens.
 */
const props = defineProps<{
    items: Array<{ key: string; label: string; variant?: 'default' | 'danger' }>;
    /** Accessible name of the trigger — the glyph alone names nothing. */
    label: string;
}>();

const emit = defineEmits<{ select: [string] }>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const menu = ref<HTMLElement | null>(null);

function close(): void {
    open.value = false;
}

/**
 * Listens on `document`, not on the root element, for the same reason
 * Modal.vue's trap does: the events worth reacting to are precisely the
 * ones that happen outside this component.
 */
function onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        close();
    }
}

function onDocumentClick(event: MouseEvent): void {
    if (!root.value?.contains(event.target as Node)) {
        close();
    }
}

function onTriggerClick(event: MouseEvent): void {
    event.stopPropagation();
    open.value = !open.value;
}

function onItemClick(event: MouseEvent, key: string): void {
    event.stopPropagation();
    close();
    emit('select', key);
}

watch(open, async (isOpen) => {
    if (isOpen) {
        document.addEventListener('keydown', onDocumentKeydown);
        // `capture` so the outside-click closes before any handler on the
        // card underneath gets a chance to act on the same click.
        document.addEventListener('click', onDocumentClick, true);
        await nextTick();
        menu.value?.querySelector<HTMLElement>('[role="menuitem"]')?.focus();

        return;
    }

    document.removeEventListener('keydown', onDocumentKeydown);
    document.removeEventListener('click', onDocumentClick, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onDocumentKeydown);
    document.removeEventListener('click', onDocumentClick, true);
});
</script>

<template>
    <div v-if="items.length > 0" ref="root" class="relative shrink-0">
        <button
            type="button"
            class="rounded-xl px-1 leading-none text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :aria-label="label"
            aria-haspopup="menu"
            :aria-expanded="open ? 'true' : 'false'"
            @click="onTriggerClick"
        >
            &hellip;
        </button>

        <div
            v-if="open"
            ref="menu"
            role="menu"
            class="absolute right-0 z-10 mt-1 min-w-32 rounded-xl border border-border bg-surface py-1 shadow-lg"
        >
            <button
                v-for="item in items"
                :key="item.key"
                type="button"
                role="menuitem"
                class="block w-full px-3 py-1.5 text-left text-xs hover:bg-surface-2 focus:outline-none focus-visible:bg-surface-2"
                :class="item.variant === 'danger' ? 'text-danger' : 'text-text'"
                @click="onItemClick($event, item.key)"
            >
                {{ item.label }}
            </button>
        </div>
    </div>
</template>
