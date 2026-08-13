import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import PostEditorModal from '../PostEditorModal.vue';
import type { Integration, Provider } from '../../../stores/integrations';

const get = vi.fn();
const post = vi.fn();
const put = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
        put: (...args: unknown[]) => put(...args),
    },
}));

function bluesky(): Integration {
    return {
        id: 'int-1',
        provider_identifier: 'bluesky',
        name: 'My Bluesky',
        picture: null,
        profile: null,
        settings: null,
        customer_name: null,
        group_name: null,
        disabled: false,
        refresh_needed: false,
        created_at: '',
        updated_at: '',
    };
}

function blueskyProvider(): Provider {
    return {
        identifier: 'bluesky',
        name: 'Bluesky',
        auth_type: 'oauth',
        capabilities: {
            maxCharacters: 300,
            maxImages: 4,
            maxVideos: 1,
            supportsThreads: true,
            supportsScheduling: true,
            allowedMimeTypes: ['image/png'],
            maxFileSizeBytes: 5_000_000,
            allowsMixedMedia: false,
            maxThreadLength: 25,
            requiresAltText: false,
        },
    };
}

function axiosError(status: number, data: unknown = {}) {
    return { isAxiosError: true, response: { status, data } };
}

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    put.mockReset();

    get.mockImplementation((url: string) => {
        if (url === '/integrations') return Promise.resolve({ data: [bluesky()] });
        if (url === '/providers') return Promise.resolve({ data: [blueskyProvider()] });
        if (url === '/sets') return Promise.resolve({ data: [] });
        if (url === '/signatures') return Promise.resolve({ data: [] });
        if (url === '/tags') return Promise.resolve({ data: [] });
        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
});

async function mountReady() {
    // Modal.vue renders through <Teleport to="body">; stubbing Teleport
    // keeps its content inline in the wrapper's own tree so plain
    // wrapper.find()/get() can reach it, instead of it landing in the
    // real document.body outside the component tree VTU walks.
    const w = mount(PostEditorModal, {
        props: { open: true, groupId: null },
        global: { stubs: { teleport: true } },
    });
    await flushPromises();
    return w;
}

async function selectChannelAndType(w: Awaited<ReturnType<typeof mountReady>>, text: string) {
    await w.find('input[type="checkbox"]').setValue(true);
    await w.get('#common-content').setValue(text);
    await flushPromises();
}

it('a 409 on save keeps the composed text intact and shows a locked notice instead of losing the draft', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'hello world');
    post.mockRejectedValueOnce(axiosError(409));

    const publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    expect(publishNow).toBeTruthy();
    await publishNow!.trigger('click');
    await flushPromises();

    expect(w.text()).toContain('This post has already gone out');
    // The text the user typed is still there, unmutated.
    expect((w.get('#common-content').element as HTMLTextAreaElement).value).toBe('hello world');
});

it('a 422 maps the error onto the right channel/segment and keeps the text editable', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'a'.repeat(400));

    post.mockRejectedValueOnce(
        axiosError(422, { errors: { 'posts.0.value.0.content': ['Content exceeds this network\'s limit.'] } }),
    );

    const publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    expect(w.text()).toContain("Content exceeds this network's limit.");
    expect((w.get('#common-content').element as HTMLTextAreaElement).value).toHaveLength(400);
});

it('a network failure (no response) shows a generic error and preserves the draft', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'network test content');

    post.mockRejectedValueOnce(new Error('Network Error'));

    const publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    expect(w.text()).toContain('Could not reach the server');
    expect((w.get('#common-content').element as HTMLTextAreaElement).value).toBe('network test content');
});

it('a successful save emits saved and close', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'good content');

    post.mockResolvedValueOnce({ data: { id: 'group-1' } });

    const publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    expect(w.emitted('saved')).toBeTruthy();
    expect(w.emitted('close')).toBeTruthy();
    expect(post).toHaveBeenCalledWith(
        '/posts',
        expect.objectContaining({
            posts: [expect.objectContaining({ integration_id: 'int-1', value: [{ content: 'good content', media_ids: [] }] })],
        }),
    );
});
