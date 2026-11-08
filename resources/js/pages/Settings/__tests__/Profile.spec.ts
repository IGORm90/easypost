import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import Profile from '../Profile.vue';
import PlanUsagePanel from '../../../components/billing/PlanUsagePanel.vue';
import { useAuthStore } from '../../../stores/auth';

vi.mock('../../../api/client', () => ({
    api: { get: vi.fn(() => Promise.resolve({ data: null })), put: vi.fn(), post: vi.fn() },
}));
vi.mock('../../../layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

/*
 * «Кобальт» lays the profile out as the mockup does: personal data on the
 * left, the plan's usage and the API key on the right — the plan moved
 * here from the Team page, next to the person who pays attention to it.
 */
async function mountPage() {
    setActivePinia(createPinia());
    useAuthStore().user = { id: 'u-1', name: 'Igor Muz', email: 'igor@example.test', timezone: 'UTC', locale: 'en', email_verified_at: null, created_at: '', updated_at: '' };
    const w = mount(Profile);
    await flushPromises();

    return w;
}

it('puts personal data in one column and the plan with the API key in the other', async () => {
    const w = await mountPage();

    expect(w.get('[data-test="profile-personal"]').find('#profile-name').exists()).toBe(true);
    expect(w.get('[data-test="profile-side"]').findComponent(PlanUsagePanel).exists()).toBe(true);
    expect(w.get('[data-test="profile-side"]').text()).toContain('API');
});

it('greets the user with their initials and email above the form', async () => {
    const w = await mountPage();

    expect(w.get('[data-test="profile-personal"]').text()).toContain('IM');
    expect(w.get('[data-test="profile-personal"]').text()).toContain('igor@example.test');
});
