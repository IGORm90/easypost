import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import Signatures from '../Signatures.vue';
import en from '../../../i18n/en.json';
import type { SignatureDto } from '../../../components/editor/types';

/**
 * Signatures were readable from the SPA long before they were manageable
 * in it — the post editor has listed them in its per-channel picker since
 * Task 23, but the only way to create one was the API by hand. These
 * specs pin the parts of the contract this screen has to respect:
 *
 *  - PUT is a full replace. `content` and `is_default` are both
 *    `required`, so editing the text must carry the flag and flipping the
 *    flag must carry the text, or the write comes back 422 on whichever
 *    field was left out (the same trap TagManager.vue documents for
 *    colours and Sets.vue for channels).
 *  - `is_default` is exclusive per organization, enforced by a partial
 *    unique index and by SignatureService::clearDefault(). Promoting one
 *    row silently demotes another server-side, so the list has to demote
 *    it locally too or the screen shows two defaults until the next load.
 *  - `content` is capped at 1000 characters and has no name column, so
 *    every row is labelled by its own text.
 */
const get = vi.fn();
const post = vi.fn();
const put = vi.fn();
const del = vi.fn();
const confirm = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
        put: (...args: unknown[]) => put(...args),
        delete: (...args: unknown[]) => del(...args),
    },
}));

// The page under test is the list and its forms, not the app chrome —
// AppLayout drags in the auth/organization stores and the theme.
vi.mock('../../../layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

function signature(overrides: Partial<SignatureDto> = {}): SignatureDto {
    return { id: 'sig-1', content: 'Best, the Newsroom team', is_default: false, ...overrides };
}

function respondWith(signatures: SignatureDto[]): void {
    get.mockImplementation((url: string) => {
        if (url === '/signatures') {
            return Promise.resolve({ data: signatures });
        }

        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
}

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    put.mockReset();
    del.mockReset();
    confirm.mockReset();
    // happy-dom ships no window.confirm at all, so it has to be defined
    // rather than spied on.
    Object.defineProperty(window, 'confirm', { value: confirm, configurable: true, writable: true });
    confirm.mockReturnValue(true);
    respondWith([signature()]);
});

async function mountPage() {
    const w = mount(Signatures);
    await flushPromises();

    return w;
}

function buttonWith(wrapper: Awaited<ReturnType<typeof mountPage>>, label: string) {
    return wrapper.findAll('button').find((button) => button.text() === label);
}

it('labels each row with its own text, because a signature has no name', async () => {
    const w = await mountPage();

    expect(get).toHaveBeenCalledWith('/signatures');
    expect(w.text()).toContain('Best, the Newsroom team');
});

it('collapses the line breaks of a multi-line signature into the row preview', async () => {
    respondWith([signature({ content: 'Best,\nthe Newsroom team' })]);
    const w = await mountPage();

    expect(w.get('[data-test="signature-preview"]').text()).toBe('Best, the Newsroom team');
});

it('explains what a signature is for instead of showing an empty list', async () => {
    respondWith([]);
    const w = await mountPage();

    expect(w.text()).toContain(en.signatures.empty);
    expect(w.text()).toContain(en.signatures.emptyHint);
});

it('creates a signature from the text and the default flag', async () => {
    respondWith([]);
    post.mockResolvedValue({ data: signature({ id: 'sig-2', content: 'See you on Friday', is_default: true }) });
    const w = await mountPage();

    await w.get('[data-test="signature-create"] textarea').setValue('See you on Friday');
    await w.get('[data-test="signature-create"] input[type="checkbox"]').setValue(true);
    await w.get('form[data-test="signature-create"]').trigger('submit');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/signatures', { content: 'See you on Friday', is_default: true });
    expect(w.text()).toContain('See you on Friday');
});

it('sends the default flag along with an edit, because PUT is a full replace', async () => {
    respondWith([signature({ is_default: true })]);
    put.mockResolvedValue({ data: signature({ content: 'Warmly, the Newsroom team', is_default: true }) });
    const w = await mountPage();

    await buttonWith(w, en.signatures.edit)!.trigger('click');
    await w.get('[data-test="signature-edit"] textarea').setValue('Warmly, the Newsroom team');
    await buttonWith(w, en.signatures.save)!.trigger('click');
    await flushPromises();

    expect(put).toHaveBeenCalledWith('/signatures/sig-1', {
        content: 'Warmly, the Newsroom team',
        is_default: true,
    });
    expect(w.text()).toContain('Warmly, the Newsroom team');
});

it('sends the text along with a promotion to default, because PUT is a full replace', async () => {
    respondWith([signature({ id: 'sig-1', is_default: true }), signature({ id: 'sig-2', content: 'See you Friday' })]);
    put.mockResolvedValue({ data: signature({ id: 'sig-2', content: 'See you Friday', is_default: true }) });
    const w = await mountPage();

    await buttonWith(w, en.signatures.makeDefault)!.trigger('click');
    await flushPromises();

    expect(put).toHaveBeenCalledWith('/signatures/sig-2', { content: 'See you Friday', is_default: true });
});

it('demotes the previous default locally, since the server has already demoted it', async () => {
    respondWith([signature({ id: 'sig-1', is_default: true }), signature({ id: 'sig-2', content: 'See you Friday' })]);
    put.mockResolvedValue({ data: signature({ id: 'sig-2', content: 'See you Friday', is_default: true }) });
    const w = await mountPage();

    await buttonWith(w, en.signatures.makeDefault)!.trigger('click');
    await flushPromises();

    // Exactly one row may claim the badge — the partial unique index
    // guarantees the server agrees, and a stale second badge here would
    // be the screen lying about which signature the editor offers first.
    expect(w.findAll('[data-test="signature-default-badge"]')).toHaveLength(1);
    // And the row that was just promoted is the one holding it.
    expect(w.findAll('[data-test="signature-row"]')[1].find('[data-test="signature-default-badge"]').exists()).toBe(true);
});

it('refuses to submit an empty signature, rather than collecting a 422 for it', async () => {
    respondWith([]);
    const w = await mountPage();

    expect((buttonWith(w, en.signatures.create)!.element as HTMLButtonElement).disabled).toBe(true);
    expect(post).not.toHaveBeenCalled();
});

it('refuses to submit a signature past the 1000-character cap', async () => {
    respondWith([]);
    const w = await mountPage();

    await w.get('[data-test="signature-create"] textarea').setValue('x'.repeat(1001));

    expect((buttonWith(w, en.signatures.create)!.element as HTMLButtonElement).disabled).toBe(true);
    expect(post).not.toHaveBeenCalled();
});

it('deletes only after the confirmation is accepted', async () => {
    confirm.mockReturnValue(false);
    const w = await mountPage();

    await buttonWith(w, en.signatures.remove)!.trigger('click');
    await flushPromises();

    expect(del).not.toHaveBeenCalled();
    expect(w.text()).toContain('Best, the Newsroom team');
});

it('drops the row once the delete goes through', async () => {
    del.mockResolvedValue({});
    const w = await mountPage();

    await buttonWith(w, en.signatures.remove)!.trigger('click');
    await flushPromises();

    expect(del).toHaveBeenCalledWith('/signatures/sig-1');
    expect(w.text()).not.toContain('Best, the Newsroom team');
});

it('puts a 422 message next to the field that caused it', async () => {
    respondWith([]);
    post.mockRejectedValue(
        Object.assign(new Error('unprocessable'), {
            isAxiosError: true,
            response: { status: 422, data: { errors: { content: ['The content field is required.'] } } },
        }),
    );
    const w = await mountPage();

    await w.get('[data-test="signature-create"] textarea').setValue('See you on Friday');
    await w.get('form[data-test="signature-create"]').trigger('submit');
    await flushPromises();

    expect(w.get('[data-test="signature-create"]').text()).toContain('The content field is required.');
});

it('reports a failed load instead of leaving an empty list that looks like "none yet"', async () => {
    get.mockRejectedValue(new Error('network'));
    const w = await mountPage();

    expect(w.text()).toContain(en.signatures.loadError);
    expect(w.text()).not.toContain(en.signatures.empty);
});
