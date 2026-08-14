import { mount } from '@vue/test-utils';
import Modal from '../Modal.vue';

function mountModal(props: Record<string, unknown> = {}) {
    return mount(Modal, {
        props: { open: true, title: 'Title', ...props },
        global: { stubs: { teleport: true } },
    });
}

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
