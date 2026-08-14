import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createWebHistory } from 'vue-router';
import Callback from '../Callback.vue';
import { i18n } from '../../../i18n';

const post = vi.fn();

vi.mock('../../../api/client', () => ({
    api: { post: (...args: unknown[]) => post(...args) },
}));

vi.mock('../../../layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

async function mountAt(query: Record<string, string>) {
    const router = createRouter({
        history: createWebHistory(),
        routes: [
            { path: '/integrations/callback', name: 'callback', component: Callback },
            { path: '/channels', name: 'channels', component: { template: '<div />' } },
        ],
    });

    router.push({ path: '/integrations/callback', query });
    await router.isReady();

    const w = mount(Callback, { global: { plugins: [router] } });
    await flushPromises();

    return w;
}

beforeEach(() => {
    setActivePinia(createPinia());
    i18n.global.locale.value = 'en';
    post.mockReset();
    sessionStorage.setItem('channel_connect_pending', JSON.stringify({ provider: 'mastodon', state: 'the-state', extra: {} }));
});

// Task 36 fix round 1, Minor 5: a 402 from the callback request already
// shows the global warning toast (api/client.ts's interceptor) — this
// page's own `text-danger` alert line must not also render for that
// event (color.md §3.8). The page must still leave `status === 'pending'`
// so the "Back to channels" button appears — only the red text itself is
// suppressed.
it('shows no local error text for a 402, but still offers a way back to channels', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true, response: { status: 402, data: { message: 'limit reached' } } });

    const w = await mountAt({ state: 'the-state' });

    expect(w.find('[role="alert"]').exists()).toBe(false);
    expect(w.text()).toContain('Back to channels');
});

it('still shows local error text for a generic failure', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true, response: { status: 500, data: {} } });

    const w = await mountAt({ state: 'the-state' });

    expect(w.find('[role="alert"]').exists()).toBe(true);
});
