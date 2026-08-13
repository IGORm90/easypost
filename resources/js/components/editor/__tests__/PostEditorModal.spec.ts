import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import PostEditorModal from '../PostEditorModal.vue';
import { useAuthStore } from '../../../stores/auth';
import type { Integration, Provider } from '../../../stores/integrations';
import type { PostGroupApi } from '../types';

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

function groupApi(overrides: Partial<PostGroupApi> = {}): PostGroupApi {
    return {
        id: 'group-b',
        state: 'DRAFT',
        publish_date: null,
        error: null,
        share_token: null,
        tags: [],
        posts: [
            {
                integration_id: 'int-1',
                settings: {},
                value: [
                    {
                        id: 'post-1',
                        content: "group B's own text",
                        media_ids: [],
                        order: 0,
                        parent_post_id: null,
                        state: 'DRAFT',
                        release_url: null,
                        error: null,
                        published_at: null,
                    },
                ],
            },
        ],
        created_at: '',
        updated_at: '',
        ...overrides,
    };
}

let groupFixtures: Record<string, PostGroupApi> = {};

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    put.mockReset();
    groupFixtures = { 'group-b': groupApi() };

    get.mockImplementation((url: string) => {
        if (url === '/integrations') return Promise.resolve({ data: [bluesky()] });
        if (url === '/providers') return Promise.resolve({ data: [blueskyProvider()] });
        if (url === '/sets') return Promise.resolve({ data: [] });
        if (url === '/signatures') return Promise.resolve({ data: [] });
        if (url === '/tags') return Promise.resolve({ data: [] });
        if (url.startsWith('/posts/')) {
            const id = url.replace('/posts/', '');
            const fixture = groupFixtures[id];
            return fixture ? Promise.resolve({ data: fixture }) : Promise.reject(new Error(`no fixture for ${url}`));
        }
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

it('a 409 on save keeps the composed text intact, stays saveable, and retargets the next save as a create — Task 25 review, Finding 2', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'hello world');
    post.mockRejectedValueOnce(axiosError(409));

    let publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    expect(publishNow).toBeTruthy();
    await publishNow!.trigger('click');
    await flushPromises();

    expect(w.text()).toContain('saving now will create it as a new post');
    // The text the user typed is still there, unmutated.
    expect((w.get('#common-content').element as HTMLTextAreaElement).value).toBe('hello world');
    // Not a dead end: the button is still there, still enabled.
    publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    expect(publishNow).toBeTruthy();
    expect((publishNow!.element as HTMLButtonElement).disabled).toBe(false);

    // Retrying reaches the server as a NEW post (POST), not a retry of
    // the now-locked PUT — this is what actually saves the user's work.
    post.mockResolvedValueOnce({ data: { id: 'group-new' } });
    await publishNow!.trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledTimes(2);
    expect(put).not.toHaveBeenCalled();
    expect(w.emitted('saved')).toBeTruthy();
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

it("a previous new-post composition never leaks into the next group opened — Task 25 review, Finding 1", async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'Black Friday sale');
    // Simulate the parent closing the modal without necessarily saving
    // (e.g. the user cancels) and then reopening it for a DIFFERENT,
    // existing group — the exact repro from the review.
    await w.setProps({ open: false });
    await w.setProps({ open: true, groupId: 'group-b' });
    await flushPromises();

    // group-b already has one channel selected with its own text; the
    // leaked "Black Friday sale" must not appear anywhere in the form.
    expect(w.text()).not.toContain('Black Friday sale');
    expect(w.get('#common-content').element).toHaveProperty('value', '');
    const textareas = w.findAll('textarea');
    const hasLeak = textareas.some((t) => (t.element as HTMLTextAreaElement).value.includes('Black Friday sale'));
    expect(hasLeak).toBe(false);
});

it("editing an existing group relabels the signature default instead of implying none was applied — Task 25 review, Finding 4", async () => {
    const w = mount(PostEditorModal, {
        props: { open: true, groupId: 'group-b' },
        global: { stubs: { teleport: true } },
    });
    await flushPromises();

    const select = w.get('#channel-signature');
    const defaultOption = select.findAll('option')[0];
    expect(defaultOption.text()).not.toBe('No signature');
    expect(defaultOption.text()).toContain('None selected');
    expect(w.text()).toContain('may already include a signature');
});

it("the publish-date picker converts through the profile timezone, not the test runner's own zone — Task 25 review, Finding 3", async () => {
    const auth = useAuthStore();
    auth.user = {
        id: 'user-1',
        name: 'Test',
        email: 'test@example.com',
        timezone: 'Europe/Moscow',
        locale: 'en',
        email_verified_at: null,
        created_at: '',
        updated_at: '',
    };

    const w = await mountReady();
    await w.find('input[type="checkbox"]').setValue(true);

    const findSlotGet = get.getMockImplementation();
    get.mockImplementation((url: string, config?: { params?: Record<string, unknown> }) => {
        if (url === '/posts/find-slot') {
            return Promise.resolve({ data: { publish_date: '2026-08-20T09:00:00.000Z' } });
        }
        return findSlotGet ? findSlotGet(url, config) : Promise.reject(new Error(`unexpected GET ${url}`));
    });

    const channelSelect = w.get('#slot-integration');
    await channelSelect.setValue('int-1');
    const findSlotButton = w.findAll('button').find((b) => b.text() === 'Find free slot');
    await findSlotButton!.trigger('click');
    await flushPromises();

    // 2026-08-20T09:00:00Z is 12:00 in Europe/Moscow (UTC+3) — never the
    // test runner's own timezone (which this suite otherwise runs in).
    expect((w.get('#publish-date').element as HTMLInputElement).value).toBe('2026-08-20T12:00');
});
