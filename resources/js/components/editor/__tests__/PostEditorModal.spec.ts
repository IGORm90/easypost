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
        auth_fields: [],
        settings_fields: [],
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

    // Clicking again does NOT immediately save — Task 25 review round 2,
    // Finding 3: a locked group requires an explicit confirmation before
    // the next click is allowed to actually create a second, live post.
    await publishNow!.trigger('click');
    await flushPromises();
    expect(post).toHaveBeenCalledTimes(1); // still just the failed attempt
    expect(w.text()).toContain('This will publish a second, separate post');

    const confirm = w.findAll('button').find((b) => b.text() === 'Yes, create a new post');
    expect(confirm).toBeTruthy();

    // Retrying reaches the server as a NEW post (POST), not a retry of
    // the now-locked PUT — this is what actually saves the user's work.
    post.mockResolvedValueOnce({ data: { id: 'group-new' } });
    await confirm!.trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledTimes(2);
    expect(put).not.toHaveBeenCalled();
    expect(w.emitted('saved')).toBeTruthy();
});

it('cancelling the confirmation leaves the draft untouched and does not save', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'careful text');
    post.mockRejectedValueOnce(axiosError(409));

    let publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    // Re-query: the button is re-rendered with a new loading/disabled
    // state between clicks, so a stale wrapper reference from before
    // the first click does not reliably dispatch a second trigger.
    publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click'); // requests confirmation, does not save
    await flushPromises();

    const cancel = w.findAll('button').find((b) => b.text() === 'Cancel');
    expect(cancel).toBeTruthy();
    await cancel!.trigger('click');
    await flushPromises();

    expect(w.text()).not.toContain('This will publish a second, separate post');
    expect(post).toHaveBeenCalledTimes(1);
    expect((w.get('#common-content').element as HTMLTextAreaElement).value).toBe('careful text');
});

it('an already-published group requires confirmation before the very first save, not just after a 409', async () => {
    groupFixtures['group-b'] = groupApi({ state: 'PUBLISHED' });
    const w = mount(PostEditorModal, {
        props: { open: true, groupId: 'group-b' },
        global: { stubs: { teleport: true } },
    });
    await flushPromises();

    expect(w.text()).toContain('saving now will create it as a new post');
    const publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    // No request went out yet — only the confirmation is showing.
    expect(post).not.toHaveBeenCalled();
    expect(put).not.toHaveBeenCalled();
    expect(w.text()).toContain('This will publish a second, separate post');
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

it('the signature warning survives a 409 re-target — it tracks content provenance, not effectiveGroupId — Task 25 review, round 3', async () => {
    const w = mount(PostEditorModal, {
        props: { open: true, groupId: 'group-b' },
        global: { stubs: { teleport: true } },
    });
    await flushPromises();

    // Sanity: loaded from an existing (not-yet-published) group, so the
    // warning is already showing before any save attempt.
    expect(w.text()).toContain('may already include a signature');

    // group-b is a DRAFT (not yet published), so `effectiveGroupId` is
    // still 'group-b' at this point — the very first save is a PUT, not
    // a POST (see `executeSave()`).
    put.mockRejectedValueOnce(axiosError(409));
    const publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    // effectiveGroupId is now null and the header would say "New post"
    // — but the composed text is UNCHANGED, still server-loaded, so the
    // signature selector must keep warning about it rather than
    // reverting to "No signature" (the exact double-append bug round 1
    // closed, reopened by keying this off the wrong flag).
    expect(w.text()).toContain('saving now will create it as a new post'); // confirms the re-target happened
    const select = w.get('#channel-signature');
    expect(select.findAll('option')[0].text()).not.toBe('No signature');
    expect(w.text()).toContain('may already include a signature');
});

it("attaching media to one channel does not freeze that channel's live sync with the shared textarea — Task 29 review, finding 5", async () => {
    const channelB: Integration = { ...bluesky(), id: 'int-2', name: 'My Bluesky 2' };

    get.mockImplementation((url: string) => {
        if (url === '/integrations') return Promise.resolve({ data: [bluesky(), channelB] });
        if (url === '/providers') return Promise.resolve({ data: [blueskyProvider()] });
        if (url === '/sets') return Promise.resolve({ data: [] });
        if (url === '/signatures') return Promise.resolve({ data: [] });
        if (url === '/tags') return Promise.resolve({ data: [] });
        if (url === '/media') {
            return Promise.resolve({
                data: {
                    data: [
                        {
                            id: 'med-a',
                            url: 'https://cdn.example.com/a.jpg',
                            thumbnail_url: null,
                            mime: 'image/jpeg',
                            size_bytes: 10,
                            width: 100,
                            height: 100,
                            original_filename: 'a.jpg',
                            created_at: '',
                        },
                    ],
                    meta: { current_page: 1, last_page: 1 },
                },
            });
        }
        return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    const w = mount(PostEditorModal, {
        props: { open: true, groupId: null },
        global: { stubs: { teleport: true } },
    });
    await flushPromises();

    // One `.setValue(true)` per checkbox, re-querying between them: this
    // component re-renders the whole checkbox list on the first
    // selection (revealing the previously-hidden common-content/tabs
    // sections shifts enough of the surrounding tree that VTU's earlier
    // wrapper reference for the second checkbox goes stale), so reusing
    // a `findAll()` captured before either click silently clicks a
    // detached node for the second checkbox.
    await w.findAll('input[type="checkbox"]')[0].setValue(true); // int-1, channel A
    await flushPromises();
    await w.findAll('input[type="checkbox"]')[1].setValue(true); // int-2, channel B
    await flushPromises();
    await w.get('#common-content').setValue('Hello');
    await flushPromises();

    // Channel A (int-1) is the active tab by default (first selected).
    // Attach media to its root segment.
    await w.get('[data-testid="media-attach-0"]').trigger('click');
    await flushPromises();

    // MediaTile and ChannelTab both render `aria-pressed` buttons; the
    // channel tabs come first in the DOM, the picker's one tile is last.
    const pressable = w.findAll('button[aria-pressed]');
    await pressable[pressable.length - 1].trigger('click');
    await w.get('[data-testid="media-picker-confirm"]').trigger('click');
    await flushPromises();

    // Continue typing in the SHARED textarea AFTER attaching media to A
    // — this is the exact repro: before the fix, A silently stopped
    // mirroring `commonContent` the moment media was attached.
    await w.get('#common-content').setValue('Hello world');
    await flushPromises();

    const tabB = w.findAll('button').find((b) => b.text().includes('My Bluesky 2'));
    await tabB!.trigger('click');
    expect((w.get('#thread-segment-0').element as HTMLTextAreaElement).value).toBe('Hello world');

    const tabA = w.findAll('button').find((b) => b.text().includes('My Bluesky') && !b.text().includes('My Bluesky 2'));
    await tabA!.trigger('click');
    // The bug: this would still read "Hello" here, silently diverged
    // from what channel B (and the shared textarea) show.
    expect((w.get('#thread-segment-0').element as HTMLTextAreaElement).value).toBe('Hello world');

    post.mockResolvedValueOnce({ data: { id: 'group-1' } });
    const publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    // Both channels publish the SAME, latest text — and A's media_ids
    // survived the content sync (onCommonContentInput preserves the
    // existing segment's media_ids, only its content is replaced).
    expect(post).toHaveBeenCalledWith(
        '/posts',
        expect.objectContaining({
            posts: expect.arrayContaining([
                expect.objectContaining({ integration_id: 'int-1', value: [{ content: 'Hello world', media_ids: ['med-a'] }] }),
                expect.objectContaining({ integration_id: 'int-2', value: [{ content: 'Hello world', media_ids: [] }] }),
            ]),
        }),
    );
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
