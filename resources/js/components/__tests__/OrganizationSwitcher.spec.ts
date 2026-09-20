import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import OrganizationSwitcher from '../OrganizationSwitcher.vue';
import { useAuthStore } from '../../stores/auth';
import { useOrganizationStore } from '../../stores/organization';

vi.mock('../../router', () => ({ default: { push: vi.fn() } }));

beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
});

it.each([0, 1, 2])('keeps the switcher with %i workspaces and offers no creation link', async (count) => {
    useAuthStore().organizations = Array.from({ length: count }, (_, index) => ({
        id: String(index), name: `Workspace ${index}`, slug: `workspace-${index}`, created_at: '', updated_at: '',
    }));
    const store = useOrganizationStore();
    store.syncFromAuth();
    const switchTo = vi.spyOn(store, 'switchTo').mockResolvedValue();
    const wrapper = mount(OrganizationSwitcher, { global: { stubs: { RouterLink: RouterLinkStub } } });
    expect(wrapper.findComponent(RouterLinkStub).exists()).toBe(false);
    expect(wrapper.get('select').attributes('id')).toBe('organization-switcher');
    expect(wrapper.findAll('option')).toHaveLength(Math.max(1, count));
    expect(wrapper.get('label').attributes('for')).toBe('organization-switcher');
    expect(switchTo).not.toHaveBeenCalled();

    if (count > 1) {
        await wrapper.get('select').setValue('1');
        await flushPromises();
        expect(switchTo).toHaveBeenCalledWith('1');
    }
});
