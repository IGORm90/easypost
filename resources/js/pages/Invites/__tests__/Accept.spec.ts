import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createWebHistory } from 'vue-router';
import Accept from '../Accept.vue';
import { i18n } from '../../../i18n';

const post = vi.fn();
const get = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        post: (...args: unknown[]) => post(...args),
        get: (...args: unknown[]) => get(...args),
    },
}));

vi.mock('../../../layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

async function mountPage() {
    const router = createRouter({
        history: createWebHistory(),
        routes: [
            { path: '/invites/:token', name: 'invites-accept', component: Accept },
            { path: '/', name: 'home', component: { template: '<div />' } },
        ],
    });

    router.push({ name: 'invites-accept', params: { token: 'the-token' } });
    await router.isReady();

    const w = mount(Accept, { global: { plugins: [router] } });
    await flushPromises();

    return w;
}

beforeEach(() => {
    setActivePinia(createPinia());
    i18n.global.locale.value = 'en';
    post.mockReset();
    get.mockReset();
});

// Final review, Critical 1 (second instance): accepting an invite grants a
// membership server-side, but the auth store's `organizations` is only
// ever populated by login()/fetchMe(). Without a refresh here the
// organization the user just joined is missing from the switcher — and if
// it was their first, `organization_id` stays unset and every request
// after this page 403s — until they reload the whole app by hand.
it('refreshes the session membership list after accepting, so the new organization appears immediately', async () => {
    post.mockResolvedValueOnce({ data: null });
    get.mockResolvedValueOnce({
        data: {
            user: { id: 'u-1', name: 'Ann', email: 'ann@example.com', timezone: 'UTC', locale: 'en', email_verified_at: null, created_at: '', updated_at: '' },
            organizations: [{ id: 'org-9', name: 'Acme', slug: 'acme', created_at: '', updated_at: '' }],
        },
    });

    const w = await mountPage();
    await w.get('button').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/invites/the-token/accept');
    expect(get).toHaveBeenCalledWith('/auth/me');

    const { useAuthStore } = await import('../../../stores/auth');
    const { useOrganizationStore } = await import('../../../stores/organization');
    expect(useAuthStore().organizations.map((org) => org.id)).toEqual(['org-9']);
    expect(useOrganizationStore().current?.id).toBe('org-9');
});

it('does not refresh the session when accepting fails', async () => {
    post.mockRejectedValueOnce(new Error('gone'));

    const w = await mountPage();
    await w.get('button').trigger('click');
    await flushPromises();

    expect(get).not.toHaveBeenCalled();
    expect(w.find('[role="alert"]').exists()).toBe(true);
});
