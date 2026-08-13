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
