<script lang="ts">
/**
 * Every open modal, in the order it opened. Modals stack — Task 1.1
 * opens ShareLinkModal from inside PostEditorModal — and both listen for
 * Escape on `document`, so without this both answered a single press:
 * the small dialog closed AND the multi-minute composition behind it
 * went with it. Only the last entry here reacts.
 *
 * This lives in a plain `<script>` block, not in `<script setup>` below:
 * the body of `<script setup>` IS the setup function and runs once per
 * instance, which would give every modal its own private "stack" of one
 * and defeat the whole point. It is a property of the page, and there is
 * exactly one page.
 */
const stack: symbol[] = [];
</script>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * `ignoreBackdropClick` defaults to `false`, preserving every existing
 * caller's behaviour exactly (a stray click outside still closes them).
 * PostEditorModal.vue (Task 25) is the first caller to opt in — an
 * irreplaceable, multi-minute composition sitting behind a full-screen
 * backdrop should not be discardable by a single misplaced click, only
 * by the explicit close button or a deliberate in-form action.
 *
 * Final review, Important 11: this was a styled <div>, not a dialog. No
 * `role`/`aria-modal`/`aria-labelledby`, so a screen reader announced
 * nothing about being in a modal at all; no focus move, so a keyboard
 * user opening one stayed parked on the trigger behind the backdrop; no
 * trap, so tabbing walked straight out into the page underneath while it
 * was still covered; no restore on close; and no Escape, which is the
 * keyboard equivalent of the close button. Every one of those is fixed
 * below, and none of it changes what a mouse user sees.
 */
const props = withDefaults(defineProps<{ open?: boolean; title?: string; ignoreBackdropClick?: boolean }>(), {
    open: false,
    title: undefined,
    ignoreBackdropClick: false,
});

const emit = defineEmits<{ close: [] }>();

/** This instance's place in the shared `stack` above. */
const identity = Symbol('modal');

const { t } = useI18n();

const titleId = useId();
const dialog = ref<HTMLElement | null>(null);
/** The element that had focus when this modal opened, so closing can hand it back. */
let restoreFocusTo: HTMLElement | null = null;

function onBackdropClick(): void {
    if (!props.ignoreBackdropClick) {
        emit('close');
    }
}

const FOCUSABLE = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function focusables(): HTMLElement[] {
    return dialog.value ? Array.from(dialog.value.querySelectorAll<HTMLElement>(FOCUSABLE)) : [];
}

/**
 * Keeps Tab inside the dialog by wrapping at both ends. Listens on
 * `document` rather than the dialog so it still fires when focus has
 * somehow already left — the case a trap exists to recover from.
 */
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        // Deliberately not gated on `ignoreBackdropClick`: that guards
        // against a misplaced CLICK. Escape is intentional, and without
        // it a keyboard user has no equivalent of the close button.
        //
        // It IS gated on being the topmost modal — see `stack` above.
        if (stack[stack.length - 1] === identity) {
            emit('close');
        }

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const items = focusables();

    if (items.length === 0) {
        return;
    }

    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement as HTMLElement | null;

    if (event.shiftKey && (active === first || !dialog.value?.contains(active))) {
        event.preventDefault();
        last.focus();

        return;
    }

    if (!event.shiftKey && (active === last || !dialog.value?.contains(active))) {
        event.preventDefault();
        first.focus();
    }
}

watch(
    () => props.open,
    async (open) => {
        if (open) {
            restoreFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            stack.push(identity);
            document.addEventListener('keydown', onKeydown);
            await nextTick();
            (focusables()[0] ?? dialog.value)?.focus();

            return;
        }

        leaveStack();
        document.removeEventListener('keydown', onKeydown);
        restoreFocusTo?.focus();
        restoreFocusTo = null;
    },
    { immediate: true },
);

function leaveStack(): void {
    const index = stack.lastIndexOf(identity);

    if (index !== -1) {
        stack.splice(index, 1);
    }
}

onBeforeUnmount(() => {
    // A modal can be torn down while still open (its whole page unmounts),
    // which would otherwise leave a dead entry on top of the stack and
    // silence Escape for everything underneath it, permanently.
    leaveStack();
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-text/40" @click="onBackdropClick" />
            <div
                ref="dialog"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="title ? titleId : undefined"
                tabindex="-1"
                class="relative w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-lg"
            >
                <div class="mb-4 flex items-center justify-between gap-2">
                    <h2 v-if="title" :id="titleId" class="text-base font-semibold text-text">{{ title }}</h2>
                    <!--
                        Header actions (Task 1.1's "Share"). Deliberately
                        placed BEFORE the close button so both the visual
                        order and the tab order put "do something" ahead of
                        "leave" — and so `ml-auto` keeps pinning the group
                        to the right whether or not the slot is filled.
                    -->
                    <div class="ml-auto flex items-center gap-2">
                        <slot name="actions" />
                        <button
                            type="button"
                            class="rounded-xl p-1 text-muted hover:text-text focus:outline-none focus:ring-2 focus:ring-primary"
                            :aria-label="t('ui.close')"
                            @click="emit('close')"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path
                                    d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
                                />
                            </svg>
                        </button>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </Teleport>
</template>
