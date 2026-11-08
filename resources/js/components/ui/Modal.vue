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

/**
 * How many modals are currently holding the page still, and what the
 * page's own inline styles were before the first of them took over.
 *
 * A count, not a boolean: ShareLinkModal opens from inside
 * PostEditorModal, and if each instance simply cleared the lock on close,
 * dismissing the little dialog would hand scrolling back to a page that
 * is still covered by the composer.
 *
 * Module-level for the same reason as `stack` above — `<script setup>`
 * runs once per instance, and a per-instance count is a count of one.
 */
let locks = 0;
let restoreBodyStyle: { overflow: string; paddingRight: string; rootOverflow: string } | null = null;

function lockBodyScroll(): void {
    if (locks++ > 0) {
        return;
    }

    restoreBodyStyle = {
        overflow: document.body.style.overflow,
        paddingRight: document.body.style.paddingRight,
        // <html> as well as <body>: this app scrolls the document
        // element (`document.scrollingElement` is HTML), and body's
        // overflow only propagates to the viewport under conditions that
        // are easy to lose. Setting both is what actually holds the page.
        rootOverflow: document.documentElement.style.overflow,
    };

    // Hiding the scrollbar narrows the viewport, and the page underneath
    // reflows sideways the instant the modal opens. Padding by exactly
    // the width the scrollbar occupied keeps it still.
    const gutter = window.innerWidth - document.documentElement.clientWidth;

    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';

    if (gutter > 0) {
        document.body.style.paddingRight = `${gutter}px`;
    }
}

function unlockBodyScroll(): void {
    if (locks === 0 || --locks > 0) {
        return;
    }

    // Restored to whatever the page had, not blindly cleared to '' —
    // the app may legitimately be styling the body itself.
    document.documentElement.style.overflow = restoreBodyStyle?.rootOverflow ?? '';
    document.body.style.overflow = restoreBodyStyle?.overflow ?? '';
    document.body.style.paddingRight = restoreBodyStyle?.paddingRight ?? '';
    restoreBodyStyle = null;
}
</script>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
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
const props = withDefaults(defineProps<{ open?: boolean; title?: string; ignoreBackdropClick?: boolean; size?: 'md' | 'full' }>(), {
    open: false,
    title: undefined,
    ignoreBackdropClick: false,
    size: 'md',
});

const emit = defineEmits<{ close: [] }>();

/**
 * `md` was `max-w-md` — 448px — for every caller, including the post
 * composer, which is a whole workspace (channel picker, thread editor,
 * preview, schedule, comments) and was unusable at that width.
 *
 * `full` gives up being a card in the middle of the page: it is bounded
 * only by the wrapper's own p-4 gutter, capped at 90vh, and column-flexed
 * so the header stays put while the body scrolls inside it.
 *
 * Whole class strings, never `max-w-${size}` — Tailwind scans source text
 * and cannot see a class that only exists after interpolation.
 */
const SIZES: Record<'md' | 'full', string> = {
    md: 'max-w-3xl',
    full: 'max-w-none max-h-[90vh] flex flex-col',
};

const sizeClass = computed(() => SIZES[props.size]);

/** This instance's place in the shared `stack` above. */
const identity = Symbol('modal');

const { t } = useI18n();

const titleId = useId();
const dialog = ref<HTMLElement | null>(null);
/** The element that had focus when this modal opened, so closing can hand it back. */
let restoreFocusTo: HTMLElement | null = null;
/**
 * Whether THIS instance is one of the modals currently holding the page
 * still, so that closing and then unmounting cannot decrement the shared
 * count twice and unfreeze a page other modals are still covering.
 *
 * Declared here, above the `watch(..., { immediate: true })` that reads
 * it — a `let` further down the file is in its temporal dead zone when
 * that watcher fires during setup.
 */
let holdsLock = false;

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
            holdsLock = true;
            lockBodyScroll();
            document.addEventListener('keydown', onKeydown);
            await nextTick();
            (focusables()[0] ?? dialog.value)?.focus();

            return;
        }

        leaveStack();
        releaseLock();
        document.removeEventListener('keydown', onKeydown);
        restoreFocusTo?.focus();
        restoreFocusTo = null;
    },
    { immediate: true },
);

function releaseLock(): void {
    if (holdsLock) {
        holdsLock = false;
        unlockBodyScroll();
    }
}

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
    // Same reason as the stack entry: a modal can be torn down while
    // still open, and a leaked lock leaves the whole app unscrollable.
    releaseLock();
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Teleport to="body">
        <!--
            `name="modal"` drives the .modal-enter/leave-* classes below,
            which target .modal-backdrop and .modal-panel individually — see
            the <style> block for why the animation lives on the children
            and not on this wrapper. `appear` covers a caller that mounts
            with `open` already true; `:duration` is required because Vue
            reads the transition length off THIS element (the transitioned
            root), which has no `transition:` rule of its own — without it,
            `whenTransitionEnds` finds nothing to wait for and unmounts the
            leave instantly, silently reverting to the old instant pop.
        -->
        <Transition name="modal" appear :duration="{ enter: 180, leave: 120 }">
            <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="modal-backdrop absolute inset-0 bg-overlay" @click="onBackdropClick" />
                <div
                    ref="dialog"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="title ? titleId : undefined"
                    tabindex="-1"
                    class="modal-panel relative w-full rounded-2xl border border-border bg-surface p-6 shadow-lg"
                    :class="sizeClass"
                >
                    <div class="mb-4 flex shrink-0 items-center justify-between gap-2">
                        <h2 v-if="title" :id="titleId" class="text-lg font-semibold text-text">{{ title }}</h2>
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
        </Transition>
    </Teleport>
</template>

<style scoped>
/*
 * Deliberately no transition rule on the wrapper `<div>` that `<Transition>`
 * actually toggles (the `.fixed inset-0 …` element) — only its two children,
 * .modal-backdrop and .modal-panel, animate. That split is why the
 * `:duration` prop on the <Transition> in the template is required, not
 * optional: Vue's `whenTransitionEnds` looks at the CSS transition duration
 * of the transitioned element itself, finds none here, and would otherwise
 * remove the leave from the DOM on the very next frame — see the comment
 * there.
 *
 * "Quiet lift": the panel rises slightly and settles rather than just
 * fading, close is faster than open so dismissing feels immediate while
 * opening feels deliberate, and scoped styles work through Teleport because
 * the compiler stamps `data-v-*` on the elements THIS component renders,
 * regardless of where in the DOM they end up mounted.
 */
.modal-enter-active .modal-backdrop {
    transition: opacity 150ms ease-out;
}

.modal-leave-active .modal-backdrop {
    transition: opacity 120ms ease-in;
}

.modal-enter-from .modal-backdrop,
.modal-leave-to .modal-backdrop {
    opacity: 0;
}

.modal-enter-active .modal-panel {
    transition:
        opacity 180ms ease-out,
        transform 180ms cubic-bezier(0.16, 1, 0.3, 1);
}

.modal-leave-active .modal-panel {
    transition:
        opacity 120ms ease-in,
        transform 120ms ease-in;
}

.modal-enter-from .modal-panel {
    opacity: 0;
    transform: translateY(8px) scale(0.98);
}

.modal-leave-to .modal-panel {
    opacity: 0;
    transform: scale(0.98);
}

/*
 * The lift itself (translateY + scale) is the part that can trigger
 * vestibular discomfort, not the fade — so reduced motion keeps the opacity
 * transition but drops the transform and shortens the duration, rather than
 * disabling the transition outright.
 */
@media (prefers-reduced-motion: reduce) {
    .modal-enter-from .modal-panel,
    .modal-leave-to .modal-panel {
        transform: none;
    }

    .modal-enter-active .modal-panel,
    .modal-enter-active .modal-backdrop {
        transition-duration: 80ms;
    }
}
</style>
