import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import CalendarIndex from '../Index.vue';
import TagFilter from '../../../components/calendar/TagFilter.vue';
import TagManager from '../../../components/tags/TagManager.vue';
import ViewSwitcher from '../../../components/calendar/ViewSwitcher.vue';
import DisplayModeSwitcher from '../../../components/calendar/DisplayModeSwitcher.vue';
import StateFilter from '../../../components/calendar/StateFilter.vue';
import PostList from '../../../components/calendar/PostList.vue';

const get = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
}));

// Same reasoning as the media page spec: AppLayout drags in the whole
// app chrome, and CalendarGrid drags in FullCalendar, which happy-dom
// cannot render. This spec is about the tag-management seam.
vi.mock('../../../layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));
vi.mock('../../../components/calendar/CalendarGrid.vue', () => ({
    default: { template: '<div />' },
}));
vi.mock('../../../components/editor/PostEditorModal.vue', () => ({
    default: { template: '<div />' },
}));
vi.mock('../../../components/calendar/PublishErrorBanner.vue', () => ({
    default: { name: 'PublishErrorBanner', template: '<div />' },
}));

function tag(id: string, name: string) {
    return { id, name, color: '#0F726B', post_groups_count: 0 };
}

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    get.mockImplementation((url: string) => {
        if (url === '/tags') {
            return Promise.resolve({ data: [tag('t-1', 'Launch'), tag('t-2', 'Promo')] });
        }

        // GET /api/posts is the one endpoint here that answers with a
        // paginated envelope rather than a bare array — mirroring it
        // matters, because the store reads `data.meta` to decide whether
        // another page exists.
        if (url === '/posts') {
            return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1 } } });
        }

        return Promise.resolve({ data: [] });
    });
});

/*
 * The page reads and writes `?mode=`/`?state=` (Block 2), so it now needs
 * a real router — `test-setup.ts` installs i18n globally but nothing
 * else, and `useRoute()` returns undefined without one. A memory router,
 * not the app's: this spec is about the page, not about navigation.
 */
function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/', name: 'home', component: { template: '<div />' } }],
    });
}

async function mountPage(query: Record<string, string> = {}) {
    const router = makeRouter();
    await router.push({ path: '/', query });
    await router.isReady();

    const w = mount(CalendarIndex, { global: { plugins: [router], stubs: { teleport: true } } });
    await flushPromises();

    return { w, router };
}

it('offers a way into tag management from the calendar', async () => {
    const { w } = await mountPage();

    expect(w.findComponent(TagManager).exists()).toBe(true);
});

/*
 * The filter is a list of ids, and the tags behind them can be deleted
 * from the very modal this page opens. Left alone, the calendar would go
 * on filtering by an id that no longer exists and show an empty month
 * with nothing on screen explaining why.
 */
it('drops a deleted tag from the active filter when the tag list comes back without it', async () => {
    const { w } = await mountPage();

    w.findComponent(TagFilter).vm.$emit('update:modelValue', ['t-1', 't-2']);
    await flushPromises();
    expect(w.findComponent(TagFilter).props('modelValue')).toEqual(['t-1', 't-2']);

    get.mockImplementation((url: string) => {
        if (url === '/tags') {
            return Promise.resolve({ data: [tag('t-1', 'Launch')] });
        }

        return Promise.resolve({ data: [] });
    });

    w.findComponent(TagManager).vm.$emit('changed');
    await flushPromises();

    expect(w.findComponent(TagFilter).props('modelValue')).toEqual(['t-1']);
});

/*
 * Block 2: the grid/list toggle.
 */
it('starts on the grid, with no list and no state filter in sight', async () => {
    const { w } = await mountPage();

    expect(w.findComponent(PostList).exists()).toBe(false);
    expect(w.findComponent(StateFilter).exists()).toBe(false);
    expect(w.findComponent(ViewSwitcher).exists()).toBe(true);
});

it('swaps the grid for the list, and hides the controls that only mean something for a grid', async () => {
    const { w } = await mountPage();

    w.findComponent(DisplayModeSwitcher).vm.$emit('update:modelValue', 'list');
    await flushPromises();

    expect(w.findComponent(PostList).exists()).toBe(true);
    expect(w.findComponent(StateFilter).exists()).toBe(true);
    // Month/week/day is a zoom level of the grid, and the error banner is
    // scoped to the range the (now unmounted) grid last reported.
    expect(w.findComponent(ViewSwitcher).exists()).toBe(false);
    expect(w.findComponent({ name: 'PublishErrorBanner' }).exists()).toBe(false);
});

it('requests the list from the API when the list is shown', async () => {
    const { w } = await mountPage();
    get.mockClear();

    w.findComponent(DisplayModeSwitcher).vm.$emit('update:modelValue', 'list');
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/posts', expect.objectContaining({ params: expect.objectContaining({ page: 1 }) }));
});

it('puts the mode and the state filter in the query string, so the link can be forwarded', async () => {
    const { w, router } = await mountPage();

    w.findComponent(DisplayModeSwitcher).vm.$emit('update:modelValue', 'list');
    await flushPromises();
    expect(router.currentRoute.value.query.mode).toBe('list');

    w.findComponent(StateFilter).vm.$emit('update:modelValue', 'ERROR');
    await flushPromises();
    expect(router.currentRoute.value.query.state).toBe('ERROR');
});

it('opens straight into the filtered list when the URL says so', async () => {
    const { w } = await mountPage({ mode: 'list', state: 'ERROR' });

    expect(w.findComponent(PostList).exists()).toBe(true);
    expect(w.findComponent(StateFilter).props('modelValue')).toBe('ERROR');
    expect(get).toHaveBeenCalledWith('/posts', expect.objectContaining({ params: expect.objectContaining({ state: 'ERROR' }) }));
});

it('falls back to sane defaults instead of an empty screen when the query is nonsense', async () => {
    const { w } = await mountPage({ mode: 'gallery', state: 'WHATEVER' });

    expect(w.findComponent(PostList).exists()).toBe(false);
    expect(w.findComponent(ViewSwitcher).exists()).toBe(true);
});

/*
 * A failed load empties nothing — it just fails. Rendering the list's
 * empty state underneath the error toast would state as fact ("No
 * drafts") something the page has no idea about, which is worse than
 * saying nothing.
 */
it('does not claim the list is empty when the load failed', async () => {
    const { w } = await mountPage();

    get.mockImplementation((url: string) => {
        if (url === '/posts') {
            return Promise.reject(new Error('network'));
        }

        return Promise.resolve({ data: [] });
    });

    w.findComponent(DisplayModeSwitcher).vm.$emit('update:modelValue', 'list');
    await flushPromises();

    expect(w.text()).toContain('Could not load the list of posts.');
    expect(w.findComponent(PostList).exists()).toBe(false);
});

/*
 * Block 4. With lazy generation there is only ever ONE future occurrence
 * of a series, so deleting it is not "removing one of many" — it ends the
 * series outright, and nothing else on screen would say so afterwards.
 */
it('warns that deleting the pending occurrence ends the whole series', async () => {
    const confirm = vi.fn(() => false);
    Object.defineProperty(window, 'confirm', { value: confirm, writable: true, configurable: true });

    get.mockImplementation((url: string) => {
        if (url === '/tags') return Promise.resolve({ data: [] });
        if (url === '/posts') {
            return Promise.resolve({
                data: {
                    data: [
                        {
                            id: 'group-r',
                            state: 'QUEUE',
                            publish_date: '2026-08-20T09:00:00.000000Z',
                            share_token: null,
                            repeats: true,
                            preview: 'weekly',
                            channels: [],
                            tags: [],
                            comments_count: 0,
                        },
                    ],
                    meta: { current_page: 1, last_page: 1 },
                },
            });
        }
        return Promise.resolve({ data: [] });
    });

    const { w } = await mountPage({ mode: 'list' });

    w.findComponent(PostList).vm.$emit('remove', 'group-r');
    await flushPromises();

    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('stops the series'));
});

it('asks the ordinary question for a post that does not repeat', async () => {
    const confirm = vi.fn(() => false);
    Object.defineProperty(window, 'confirm', { value: confirm, writable: true, configurable: true });

    get.mockImplementation((url: string) => {
        if (url === '/tags') return Promise.resolve({ data: [] });
        if (url === '/posts') {
            return Promise.resolve({
                data: {
                    data: [
                        {
                            id: 'group-o',
                            state: 'QUEUE',
                            publish_date: '2026-08-20T09:00:00.000000Z',
                            share_token: null,
                            repeats: false,
                            preview: 'one-off',
                            channels: [],
                            tags: [],
                            comments_count: 0,
                        },
                    ],
                    meta: { current_page: 1, last_page: 1 },
                },
            });
        }
        return Promise.resolve({ data: [] });
    });

    const { w } = await mountPage({ mode: 'list' });

    w.findComponent(PostList).vm.$emit('remove', 'group-o');
    await flushPromises();

    expect(confirm).toHaveBeenCalledWith(expect.not.stringContaining('stops the series'));
});
