import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import LimitToast from '../LimitToast.vue';
import Modal from '../Modal.vue';
import { useLimitToastStore } from '../../../stores/limitToast';

beforeEach(() => {
    setActivePinia(createPinia());
    document.body.innerHTML = '';
});

function zIndexOf(el: Element | null): number {
    if (!el) {
        throw new Error('element not found');
    }

    for (const className of el.classList) {
        const bracketed = className.match(/^z-\[(\d+)\]$/);
        const plain = className.match(/^z-(\d+)$/);
        const match = bracketed ?? plain;

        if (match) {
            return Number(match[1]);
        }
    }

    throw new Error(`no z-index utility class found on "${el.className}"`);
}

// Round 1 re-review, residual 1: Modal.vue teleports to <body> as `fixed
// inset-0 z-50` with a 40%-opacity scrim. LimitToast.vue used to stay
// inside AppLayout's own tree at the SAME z-50 — later in DOM order, the
// modal's scrim painted over it, so a 402 raised from inside a modal
// (ConnectProviderModal, or MediaUploader inside MediaPicker) left only a
// dimmed toast behind an overlay. This proves the toast now always
// outranks any open Modal.vue instance, and does so regardless of where
// in the component tree it is mounted (real Teleport, not merely a
// higher z-index that happens to work today because AppLayout has no
// intervening stacking context).
it('sits at a higher z-index than Modal.vue\'s scrim, so an open modal never paints over it', async () => {
    useLimitToastStore().show('Reached a plan limit');

    mount(LimitToast, { attachTo: document.body });
    mount(Modal, { props: { open: true, title: 'Connect a channel' }, attachTo: document.body });
    await flushPromises();

    const toastWrapper = document.body.querySelector('[role="status"]')?.closest('.fixed') ?? null;
    const modalScrimWrapper = document.body.querySelector('.bg-text\\/40')?.closest('.fixed') ?? null;

    expect(zIndexOf(toastWrapper)).toBeGreaterThan(zIndexOf(modalScrimWrapper));
});

it('teleports its content to <body>, regardless of where it is mounted in the component tree', async () => {
    useLimitToastStore().show('Reached a plan limit');

    const w = mount(LimitToast);
    await flushPromises();

    // Not left behind in the wrapper's own (un-teleported) root...
    expect(w.find('[role="status"]').exists()).toBe(false);
    // ...but present in <body>, in the same stacking context every
    // Modal.vue instance teleports into.
    expect(document.body.querySelector('[role="status"]')).not.toBeNull();
});

it('renders nothing at all when there is no limit message to show', () => {
    mount(LimitToast);

    expect(document.body.querySelector('[role="status"]')).toBeNull();
});
