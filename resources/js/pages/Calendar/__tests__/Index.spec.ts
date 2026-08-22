import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import CalendarIndex from '../Index.vue';
import TagFilter from '../../../components/calendar/TagFilter.vue';
import TagManager from '../../../components/tags/TagManager.vue';

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
    default: { template: '<div />' },
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

        return Promise.resolve({ data: [] });
    });
});

async function mountPage() {
    const w = mount(CalendarIndex, { global: { stubs: { teleport: true } } });
    await flushPromises();

    return w;
}

it('offers a way into tag management from the calendar', async () => {
    const w = await mountPage();

    expect(w.findComponent(TagManager).exists()).toBe(true);
});

/*
 * The filter is a list of ids, and the tags behind them can be deleted
 * from the very modal this page opens. Left alone, the calendar would go
 * on filtering by an id that no longer exists and show an empty month
 * with nothing on screen explaining why.
 */
it('drops a deleted tag from the active filter when the tag list comes back without it', async () => {
    const w = await mountPage();

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
