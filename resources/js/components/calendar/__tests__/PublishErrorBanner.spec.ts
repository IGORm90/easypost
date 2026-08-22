import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import PublishErrorBanner from '../PublishErrorBanner.vue';
import { i18n } from '../../../i18n';

const get = vi.fn();
const post = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
    },
}));

const RANGE = { from: '2026-09-01', to: '2026-09-30' };

function failure(overrides: Record<string, unknown> = {}) {
    return {
        id: 'grp-1',
        publish_date: '2026-09-10T09:00:00Z',
        error: 'Publishing failed on 1 of 2 channels.',
        channels: [
            { integration: { id: 'int-1', name: 'Marketing Slack' }, error: 'channel_not_found' },
        ],
        ...overrides,
    };
}

async function mountBanner(range: { from: string; to: string } | null = RANGE) {
    setActivePinia(createPinia());

    const wrapper = mount(PublishErrorBanner, {
        props: { range },
        global: { plugins: [i18n] },
    });

    await flushPromises();

    return wrapper;
}

beforeEach(() => {
    get.mockReset();
    post.mockReset();
    get.mockResolvedValue({ data: [failure()] });
    post.mockResolvedValue({ data: {} });
});

/*
 * docs/ACCEPTANCE.md §8 instructs a human to find a failed post in the UI
 * and click retry. Both endpoints existed since Task 32 and had zero
 * callers anywhere in the SPA, so the step could not be performed at all
 * — final review, batch 4 (coverage and gates).
 */
it('asks only about the range the calendar is showing', async () => {
    await mountBanner();

    expect(get).toHaveBeenCalledWith('/posts/errors', { params: RANGE });
});

it('does not ask before the calendar has reported a range', async () => {
    await mountBanner(null);

    expect(get).not.toHaveBeenCalled();
});

it('shows the group reason and the per-channel detail', async () => {
    const w = await mountBanner();

    // Both, not either: "which post failed" and "which of its channels"
    // are different questions, and a partially-failed group needs both.
    expect(w.text()).toContain('Publishing failed on 1 of 2 channels.');
    expect(w.text()).toContain('Marketing Slack');
    expect(w.text()).toContain('channel_not_found');
});

it('says something readable when no reason was recorded', async () => {
    get.mockResolvedValue({ data: [failure({ error: null, channels: [{ integration: { id: 'i', name: 'X' }, error: null }] })] });

    const w = await mountBanner();

    expect(w.text()).toContain(i18n.global.t('calendar.errors.unknownReason'));
});

it('renders nothing at all when there are no failures', async () => {
    get.mockResolvedValue({ data: [] });

    const w = await mountBanner();

    expect(w.find('[data-test="publish-error-banner"]').exists()).toBe(false);
});

it('retries the group and tells the page to refresh', async () => {
    const w = await mountBanner();

    await w.get('[data-test="retry-grp-1"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/posts/grp-1/retry');
    expect(w.emitted('retried')).toHaveLength(1);
    // Dropped locally so a second click cannot re-send while the page
    // reload is still in flight.
    expect(w.find('[data-test="retry-grp-1"]').exists()).toBe(false);
});

it('explains a 409 as "no longer retryable", not as a generic failure', async () => {
    post.mockRejectedValue({ isAxiosError: true, response: { status: 409 } });

    const w = await mountBanner();

    await w.get('[data-test="retry-grp-1"]').trigger('click');
    await flushPromises();

    expect(w.get('[data-test="retry-error"]').text()).toBe(i18n.global.t('calendar.errors.retryConflict'));
    expect(w.emitted('retried')).toBeUndefined();
});

it('keeps the calendar usable when the error list itself cannot be loaded', async () => {
    // This banner annotates the calendar; a calendar that loaded fine
    // must not be replaced by an error because its annotation failed.
    get.mockRejectedValue(new Error('errors endpoint is down'));

    const w = await mountBanner();

    expect(w.find('[data-test="publish-error-banner"]').exists()).toBe(false);
});

/*
 * The banner had no way out: it renders whenever /posts/errors returns a
 * non-empty list, sits above the calendar grid, and only disappears once
 * every group is retried away. An operator who has already seen it needs
 * to be able to put it aside.
 */
it('offers a way to close the banner', async () => {
    const w = await mountBanner();

    expect(w.get('[data-test="dismiss-publish-errors"]').attributes('aria-label')).toBe(
        i18n.global.t('ui.close'),
    );
});

it('hides the banner when the close button is clicked', async () => {
    const w = await mountBanner();

    await w.get('[data-test="dismiss-publish-errors"]').trigger('click');

    expect(w.find('[data-test="publish-error-banner"]').exists()).toBe(false);
});

it('stays closed when the calendar moves to another range', async () => {
    // Dismissal is a decision about this session, not about this month:
    // paging the calendar refetches, but must not resurrect the banner.
    const w = await mountBanner();

    await w.get('[data-test="dismiss-publish-errors"]').trigger('click');
    await w.setProps({ range: { from: '2026-10-01', to: '2026-10-31' } });
    await flushPromises();

    expect(get).toHaveBeenCalledTimes(2);
    expect(w.find('[data-test="publish-error-banner"]').exists()).toBe(false);
});
