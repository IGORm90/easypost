import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import PostEditorModal from '../PostEditorModal.vue';
import Modal from '../../ui/Modal.vue';
import { useAuthStore } from '../../../stores/auth';
import type { Integration, Provider } from '../../../stores/integrations';
import type { PostGroupApi } from '../types';
import ChannelPicker from '../ChannelPicker.vue';
import TagManager from '../../tags/TagManager.vue';
import TagFilter from '../../calendar/TagFilter.vue';
import en from '../../../i18n/en.json';

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
        post_settings: [],
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
            supportedMarks: [],
            requiresMedia: false,
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
        repeat_unit: null,
        repeat_interval: null,
        repeat_until: null,
        repeat_parent_id: null,
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
        // Task 1.6: the comment thread mounts alongside an existing
        // group and reads its own endpoint. Answered before the group
        // branch below, which would otherwise treat
        // `/posts/group-b/comments` as a group id.
        if (url.startsWith('/posts/') && url.endsWith('/comments')) return Promise.resolve({ data: [] });
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

// Final review, Important 9 (color.md §3.8: never put accent and warning
// in one component, and §3.3: one colour, one meaning). The editor shows
// PreviewPane's accent-tinted channel avatars, and on the very same
// screen --warning meant four unrelated things at once: "this group is
// locked", "confirm creating a new post", "schedule notice" and "plan
// limit reached". CalendarEvent.vue already downgrades DRAFT to neutral
// for exactly this rule; the editor did not. Only the plan-limit toast
// keeps --warning.
it('renders no --warning anywhere in the editor, leaving that colour to the plan-limit toast', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'hello world');

    post.mockRejectedValueOnce(axiosError(409));
    let publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    // The locked Toast and the schedule notice are both on screen now.
    expect(w.text()).toContain('saving now will create it as a new post');
    expect(w.html()).not.toMatch(/(?:bg|text|border)-warning/);

    publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    // ...and now the confirmation Toast as well.
    expect(w.text()).toContain('This will publish a second, separate post');
    expect(w.html()).not.toMatch(/(?:bg|text|border)-warning/);
});

/*
 * Task 1.3. The tag block was gated on `availableTags.length > 0`, so on
 * an organization with no tags there was nothing on screen at all — the
 * one moment a "New tag" trigger is most needed was the one moment it
 * was hidden.
 */
it('offers to create a tag even when the organization has none yet', async () => {
    const w = await mountReady();

    expect(w.findComponent(TagManager).exists()).toBe(true);
    expect(w.findAll('button').some((b) => b.text() === en.tags.add)).toBe(true);
});

it('selects a tag created from inside the editor, so it is actually attached to the post', async () => {
    const w = await mountReady();

    w.findComponent(TagManager).vm.$emit('created', { id: 'tag-new', name: 'Promo', color: '#A94E08' });
    await flushPromises();

    expect(w.findComponent(TagFilter).props('modelValue')).toContain('tag-new');
});

/**
 * Task 1.4. ChannelPicker collects the name but deliberately makes no
 * request of its own (see its docblock), so the POST — and keeping the
 * chip list it renders in step afterwards — is this modal's job.
 */
it('saves the current channel selection as a set and offers it back as a chip', async () => {
    post.mockResolvedValueOnce({ data: { id: 'set-new', name: 'Launch crew', integration_ids: ['int-1'] } });
    const w = await mountReady();

    await w.find('input[type="checkbox"]').setValue(true);
    await w.findAll('button').find((b) => b.text() === en.sets.saveAsSet)!.trigger('click');
    await w.get('[data-test="save-set-name"] input').setValue('Launch crew');
    await w.get('form[data-test="save-set-form"]').trigger('submit');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/sets', { name: 'Launch crew', integration_ids: ['int-1'] });
    // No refetch of /sets: the created set is appended to the list this
    // modal already holds.
    expect(get.mock.calls.filter(([url]) => url === '/sets')).toHaveLength(1);
    expect(w.findComponent(ChannelPicker).props('sets')).toEqual([
        { id: 'set-new', name: 'Launch crew', integration_ids: ['int-1'] },
    ]);
});

it('says so rather than failing silently when the set cannot be saved', async () => {
    post.mockRejectedValueOnce(axiosError(422, { errors: { name: ['The name field is required.'] } }));
    const w = await mountReady();

    await w.find('input[type="checkbox"]').setValue(true);
    await w.findAll('button').find((b) => b.text() === en.sets.saveAsSet)!.trigger('click');
    await w.get('[data-test="save-set-name"] input').setValue('Launch crew');
    await w.get('form[data-test="save-set-form"]').trigger('submit');
    await flushPromises();

    expect(w.text()).toContain(en.sets.saveError);
    expect(w.findComponent(ChannelPicker).props('sets')).toEqual([]);
});

// Task 1.6: comments belong to a saved group. A brand-new post has none
// to attach to, and the endpoint is keyed on a group id that does not
// exist yet — so the thread is absent rather than empty.
it('offers no comment thread on a post that has not been saved yet', async () => {
    const w = await mountReady();

    expect(w.find('[data-test="comment-form"]').exists()).toBe(false);
});

it('loads the comment thread of the group it opened', async () => {
    const w = mount(PostEditorModal, {
        props: { open: true, groupId: 'group-b' },
        global: { stubs: { teleport: true } },
    });
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/posts/group-b/comments');
    expect(w.find('[data-test="comment-form"]').exists()).toBe(true);
    expect(w.text()).toContain(en.comments.title);
});

// --- Block 3: the shared field ------------------------------------------

it('offers emoji on the shared text field but no format toolbar', async () => {
    // The shared body is mirrored into channels whose supported marks
    // differ, so a bold typed here would mean two different things on
    // two tabs. Emoji mean the same thing everywhere.
    const w = await mountReady();
    await selectChannelAndType(w, 'hello');

    expect(w.find('#common-content').exists()).toBe(true);
    expect(w.findAll('[data-testid="emoji-trigger"]').length).toBeGreaterThan(0);
    // The per-channel tab below does carry a toolbar when its network
    // supports marks; this fixture's provider declares none, so the only
    // thing proven here is that the shared field itself never gets one.
    expect(w.find('[data-testid="format-toolbar"]').exists()).toBe(false);
});

/*
 * Block 4: the recurrence rule rides on the same payload as the post and
 * comes back on the same response, so the editor is the only place a
 * series is started, changed or stopped. There is deliberately no
 * separate "stop repeating" endpoint — a PUT without the fields is the
 * stop.
 */
it('sends the recurrence rule with the post', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'every week');

    await w.get('[data-testid="repeat-unit"]').setValue('WEEK');
    await w.get('[data-testid="repeat-interval"]').setValue('2');
    await w.get('[data-testid="repeat-until"]').setValue('2026-12-01T09:00');
    await flushPromises();

    const publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith(
        '/posts',
        expect.objectContaining({ repeat_unit: 'WEEK', repeat_interval: 2, repeat_until: expect.any(String) }),
    );
});

it('sends no recurrence at all for a post that does not repeat', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'just once');

    const publishNow = w.findAll('button').find((b) => b.text() === 'Publish now');
    await publishNow!.trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith(
        '/posts',
        expect.objectContaining({ repeat_unit: null, repeat_interval: null, repeat_until: null }),
    );
});

it('loads an existing series back into the repeat control', async () => {
    groupFixtures['group-b'] = groupApi({
        repeat_unit: 'MONTH',
        repeat_interval: 3,
        repeat_until: '2026-12-01T09:00:00.000Z',
    });

    const w = mount(PostEditorModal, {
        props: { open: true, groupId: 'group-b' },
        global: { stubs: { teleport: true } },
    });
    await flushPromises();

    expect((w.get('[data-testid="repeat-unit"]').element as HTMLSelectElement).value).toBe('MONTH');
    expect((w.get('[data-testid="repeat-interval"]').element as HTMLInputElement).value).toBe('3');
});

it('refuses to schedule a repeating post with no end date', async () => {
    // The backend requires it (an endless series would eat the monthly
    // quota forever), so the button is blocked rather than the user
    // being handed a 422.
    const w = await mountReady();
    await selectChannelAndType(w, 'weekly, forever?');
    await w.get('#publish-date').setValue('2026-09-01T09:00');
    await w.get('[data-testid="repeat-unit"]').setValue('WEEK');
    await flushPromises();

    const schedule = w.findAll('button').find((b) => b.text() === 'Schedule');
    expect((schedule!.element as HTMLButtonElement).disabled).toBe(true);
    expect(w.text()).toContain('when the repeat should stop');
});

/*
 * Block 5.0.6: a provider that needs per-post choices gets a form for
 * them on its own channel tab, rendered from the declaration alone.
 */
it('renders a channel\'s declared per-post settings on its tab, and sends them with the post', async () => {
    const provider = blueskyProvider();
    provider.post_settings = [{ key: 'title', type: 'text', required: true }];
    get.mockImplementation((url: string) => {
        if (url === '/providers') return Promise.resolve({ data: [provider] });
        if (url === '/integrations') return Promise.resolve({ data: [bluesky()] });
        if (url === '/tags') return Promise.resolve({ data: [] });
        if (url === '/sets') return Promise.resolve({ data: [] });
        if (url === '/signatures') return Promise.resolve({ data: [] });
        if (url === '/media') return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1 } } });
        return Promise.resolve({ data: [] });
    });

    const w = await mountReady();
    await selectChannelAndType(w, 'body text');

    const title = w.find('[data-field="title"]');
    expect(title.exists()).toBe(true);
    await title.setValue('a headline');
    await flushPromises();

    post.mockResolvedValueOnce({ data: { id: 'g1', state: 'DRAFT', publish_date: null, error: null, share_token: null, tags: [], posts: [] } });
    await w.findAll('button').find((b) => b.text() === 'Publish now')!.trigger('click');
    await flushPromises();

    const body = post.mock.calls.at(-1)?.[1] as { posts: Array<{ settings: Record<string, unknown> }> };
    expect(body.posts[0].settings).toMatchObject({ title: 'a headline' });
});

it('renders no per-post settings block for a provider that declares none', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'body text');

    expect(w.find('[data-testid="post-settings"]').exists()).toBe(false);
});

// The composer is a workspace, not a confirmation box: channel picker,
// thread editor, live preview, schedule controls and comments all at
// once. At the shared default width they were stacked into a 448px
// column; `full` hands it the screen and lets the body scroll inside a
// dialog whose header stays put.
it('asks the shared Modal for the full-screen size', async () => {
    const w = await mountReady();
    const modal = w.getComponent(Modal);

    expect(modal.props('size')).toBe('page');
    // Still not dismissable by a stray click — a multi-minute
    // composition may only be closed deliberately.
    expect(modal.props('ignoreBackdropClick')).toBe(true);
});

// «Кобальт» composer layout: a page of its own with three columns —
// channels, text, preview — and the schedule and save actions in a
// footer bar that stays put while the text column scrolls.
it('lays the composer out as channels | text | preview, with scheduling in the footer', async () => {
    const w = await mountReady();
    await selectChannelAndType(w, 'hello');

    expect(w.findComponent(Modal).props('size')).toBe('page');
    expect(w.get('[data-test="editor-channels"]').findComponent(ChannelPicker).exists()).toBe(true);
    expect(w.get('[data-test="editor-text"]').find('#common-content').exists()).toBe(true);
    expect(w.find('[data-test="editor-preview"]').exists()).toBe(true);
    expect(w.get('[data-test="editor-footer"]').find('#publish-date').exists()).toBe(true);
});

// «Кобальт»: "Find free slot" offers several upcoming free slots as
// buttons. The first is applied straight away (the old behaviour); any
// other is one click away. Times are shown in the profile's zone.
it('offers several free slots as buttons and applies the one clicked', async () => {
    const auth = useAuthStore();
    auth.user = { id: 'user-1', name: 'Test', email: 'test@example.com', timezone: 'UTC', locale: 'en', email_verified_at: null, created_at: '', updated_at: '' };

    const w = await mountReady();
    await w.find('input[type="checkbox"]').setValue(true);

    const fallback = get.getMockImplementation();
    get.mockImplementation((url: string, config?: { params?: Record<string, unknown> }) => {
        if (url === '/posts/find-slot') {
            return Promise.resolve({
                data: {
                    publish_date: '2026-08-20T09:00:00.000Z',
                    publish_dates: ['2026-08-20T09:00:00.000Z', '2026-08-20T14:00:00.000Z', '2026-08-21T09:00:00.000Z'],
                },
            });
        }
        return fallback ? fallback(url, config) : Promise.reject(new Error(`unexpected GET ${url}`));
    });

    await w.get('#slot-integration').setValue('int-1');
    await w.findAll('button').find((b) => b.text() === 'Find free slot')!.trigger('click');
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/posts/find-slot', { params: { integration_id: 'int-1', count: 4 } });
    const options = w.findAll('[data-test="slot-option"]');
    expect(options).toHaveLength(3);
    expect(options[0].attributes('aria-pressed')).toBe('true');
    expect((w.get('#publish-date').element as HTMLInputElement).value).toBe('2026-08-20T09:00');

    await options[1].trigger('click');
    expect((w.get('#publish-date').element as HTMLInputElement).value).toBe('2026-08-20T14:00');
    expect(w.findAll('[data-test="slot-option"]')[1].attributes('aria-pressed')).toBe('true');
});
