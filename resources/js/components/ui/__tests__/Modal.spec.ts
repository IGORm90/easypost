import { enableAutoUnmount, mount } from '@vue/test-utils';
import Modal from '../Modal.vue';

/**
 * Modal keeps module-level state that outlives a single instance — the
 * Escape `stack`, and now the body-scroll lock count. A wrapper left
 * mounted at the end of a test therefore leaks an open modal into every
 * test after it, which would make the scroll-lock assertions below pass
 * or fail depending on file order. Unmounting after each test is what
 * makes them mean anything.
 */
enableAutoUnmount(afterEach);

function mountModal(props: Record<string, unknown> = {}) {
    return mount(Modal, {
        props: { open: true, title: 'Title', ...props },
        global: { stubs: { teleport: true } },
    });
}

// The user's complaint was "it opens so fast" — the honest cause was that
// Modal had NO animation at all: a bare v-if inside a Teleport, so the
// panel and its backdrop appeared in a single frame. `<Transition>` with a
// `name` is what gives the enter/leave classes below something to hook.
it('opens through a named transition instead of appearing in a single frame', () => {
    const w = mountModal();

    expect(w.get('transition-stub').attributes('name')).toBe('modal');
});

// Only the backdrop and panel carry a `transition:` rule — the outer
// `.fixed` wrapper is layout-only. Vue's whenTransitionEnds reads the
// computed transition duration off the ROOT element of the transitioned
// node, and would find none there, firing `done` (and unmounting the leave)
// immediately. The explicit `:duration` is what keeps the leave fade from
// silently reverting to an instant pop — do not remove it as "redundant".
it('declares an explicit duration, because only its children (not the transitioned root) animate', () => {
    const w = mountModal();
    const duration = w.get('transition-stub').attributes('duration');

    expect(duration).toBeTruthy();
});

it('the backdrop and the panel each carry their own animation hook class', () => {
    const w = mountModal();

    expect(w.get('.fixed > .absolute').classes()).toContain('modal-backdrop');
    expect(w.get('[role="dialog"]').classes()).toContain('modal-panel');
});

it('a backdrop click closes the modal by default — every existing caller keeps this behaviour', async () => {
    const w = mountModal();
    await w.find('.fixed > .absolute').trigger('click');
    expect(w.emitted('close')).toBeTruthy();
});

it('ignoreBackdropClick=true swallows a backdrop click without emitting close — Task 25 review, Finding 5', async () => {
    const w = mountModal({ ignoreBackdropClick: true });
    await w.find('.fixed > .absolute').trigger('click');
    expect(w.emitted('close')).toBeFalsy();
});

it('the explicit close button still closes even with ignoreBackdropClick=true', async () => {
    const w = mountModal({ ignoreBackdropClick: true });
    await w.get('button[aria-label]').trigger('click');
    expect(w.emitted('close')).toBeTruthy();
});

// Final review, Important 11: Modal was not a dialog at all — no
// role/aria-modal/aria-labelledby, no focus move or restore, no focus
// trap and no Escape handler. PostEditorModal, a multi-minute
// composition, is built on it, and a keyboard user tabbing inside it
// walked straight out behind the backdrop into the page underneath.
it('announces itself as a modal dialog labelled by its own title', () => {
    const w = mountModal();
    const dialog = w.get('[role="dialog"]');

    expect(dialog.attributes('aria-modal')).toBe('true');

    const labelledBy = dialog.attributes('aria-labelledby');
    expect(labelledBy).toBeTruthy();
    expect(w.get(`#${labelledBy}`).text()).toBe('Title');
});

it('closes on Escape', async () => {
    const w = mountModal();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await w.vm.$nextTick();

    expect(w.emitted('close')).toBeTruthy();
});

it('closes on Escape even when a stray backdrop click must not', async () => {
    // ignoreBackdropClick protects against a MISPLACED click; Escape is a
    // deliberate keystroke and is the keyboard user's only equivalent of
    // the close button.
    const w = mountModal({ ignoreBackdropClick: true });
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await w.vm.$nextTick();

    expect(w.emitted('close')).toBeTruthy();
});

it('moves focus into the dialog on open and restores it to the opener on close', async () => {
    const opener = document.createElement('button');
    document.body.appendChild(opener);
    opener.focus();
    expect(document.activeElement).toBe(opener);

    const w = mount(Modal, {
        props: { open: false, title: 'Title' },
        attachTo: document.body,
    });

    await w.setProps({ open: true });
    await w.vm.$nextTick();
    expect(document.body.contains(document.activeElement)).toBe(true);
    expect(document.activeElement).not.toBe(opener);

    await w.setProps({ open: false });
    await w.vm.$nextTick();
    expect(document.activeElement).toBe(opener);

    w.unmount();
    opener.remove();
});

it('wraps Tab from the last focusable element back to the first, instead of letting focus escape behind the backdrop', async () => {
    const w = mount(Modal, {
        props: { open: true, title: 'Title' },
        slots: { default: '<button id="inner">Inner</button>' },
        attachTo: document.body,
    });
    await w.vm.$nextTick();

    // The dialog is teleported to document.body, so query from there.
    const focusables = Array.from(document.querySelectorAll<HTMLElement>('[role="dialog"] button'));
    const last = focusables[focusables.length - 1];
    last.focus();

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    await w.vm.$nextTick();

    expect(document.activeElement).toBe(focusables[0]);

    w.unmount();
});

// Task 1.1 (share button): PostEditorModal needs a "Share" action beside
// the title, not buried in the body among the composer's own controls.
// The slot is additive — the header of every existing caller, none of
// which passes it, is byte-for-byte what it was.
it('renders the actions slot in the header, before the close button', () => {
    const w = mount(Modal, {
        props: { open: true, title: 'Title' },
        slots: { actions: '<button type="button" class="share-action">Share</button>' },
        global: { stubs: { teleport: true } },
    });

    const header = w.get('[role="dialog"] > div');
    const buttons = header.findAll('button');

    expect(header.find('.share-action').exists()).toBe(true);
    // Close stays last, so Tab reaches the actions first — the same
    // reading order as the visual one.
    expect(buttons[buttons.length - 1].attributes('aria-label')).toBeTruthy();
});

it('leaves the header untouched when no actions slot is passed', () => {
    const w = mountModal();
    const header = w.get('[role="dialog"] > div');

    expect(header.findAll('button')).toHaveLength(1);
});

// Task 1.1 opens a share-link modal from inside PostEditorModal. Both are
// Modals, both listened for Escape on `document`, and both reacted — so
// one Escape dismissed the little dialog AND the multi-minute composition
// behind it. Only the topmost open modal may answer Escape.
it('only the topmost open modal reacts to Escape', async () => {
    const bottom = mountModal();
    const top = mountModal();

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await top.vm.$nextTick();

    expect(top.emitted('close')).toBeTruthy();
    expect(bottom.emitted('close')).toBeFalsy();
});

it('hands Escape back to the one underneath once the topmost closes', async () => {
    const bottom = mountModal();
    const top = mountModal();

    await top.setProps({ open: false });
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await bottom.vm.$nextTick();

    expect(bottom.emitted('close')).toBeTruthy();
});

// The composer is a full workspace — channels, thread editor, preview,
// schedule controls — and it was being squeezed into max-w-md (448px).
it('is wide by default, and effectively full-screen at size="full"', () => {
    const dialog = mountModal().get('[role="dialog"]');

    expect(dialog.classes()).toContain('max-w-3xl');
    expect(dialog.classes()).not.toContain('max-w-md');

    const full = mountModal({ size: 'full' }).get('[role="dialog"]');

    expect(full.classes()).toContain('max-w-none');
    expect(full.classes()).toContain('max-h-[90vh]');
    // The header stays put and the body scrolls inside it, rather than
    // the whole dialog growing past the viewport.
    expect(full.classes()).toContain('flex-col');
});

// `bg-text/40` produced NO css at all: tailwind.config.js maps every
// token to a bare `var(--token)` with no <alpha-value> placeholder, and
// Tailwind 3 drops an opacity modifier it cannot apply. The backdrop was
// catching clicks while dimming nothing. `--overlay` bakes the alpha into
// the token itself, and is a deliberate dark wash in BOTH themes —
// `--text` in the dark theme is near-white and would have washed the page
// out in light, not dimmed it.
it('dims the page with the overlay token, not with an opacity modifier that Tailwind silently drops', () => {
    const backdrop = mountModal().get('.fixed > .absolute');

    expect(backdrop.classes()).toContain('bg-overlay');
    expect(backdrop.classes().some((c) => c.includes('/'))).toBe(false);
});

it('freezes the page behind it while open, and hands scrolling back on close', async () => {
    document.body.style.overflow = 'auto';

    const w = mount(Modal, { props: { open: false, title: 'Title' }, global: { stubs: { teleport: true } } });

    expect(document.body.style.overflow).toBe('auto');

    await w.setProps({ open: true });
    expect(document.body.style.overflow).toBe('hidden');
    // <html> too: it is the element that actually scrolls in this app.
    expect(document.documentElement.style.overflow).toBe('hidden');

    await w.setProps({ open: false });
    // Restored to what it was, not blindly cleared to ''.
    expect(document.body.style.overflow).toBe('auto');

    document.body.style.overflow = '';
});

// ShareLinkModal opens from inside PostEditorModal. If each instance
// simply cleared the lock on close, dismissing the small dialog would
// unfreeze the page while the composer was still covering it.
it('keeps the page frozen until the last of a stack of modals closes', async () => {
    const bottom = mountModal();
    const top = mountModal();

    expect(document.body.style.overflow).toBe('hidden');

    await top.setProps({ open: false });
    expect(document.body.style.overflow).toBe('hidden');

    await bottom.setProps({ open: false });
    expect(document.body.style.overflow).toBe('');
    expect(document.documentElement.style.overflow).toBe('');
});

it('releases the lock when an open modal is torn down with its page', () => {
    const w = mountModal();

    expect(document.body.style.overflow).toBe('hidden');

    w.unmount();

    expect(document.body.style.overflow).toBe('');
});
