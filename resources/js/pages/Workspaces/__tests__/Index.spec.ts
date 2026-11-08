import { mount, RouterLinkStub } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick } from 'vue';
import Index from '../Index.vue';
import { useAuthStore } from '../../../stores/auth';
import { useOrganizationStore } from '../../../stores/organization';

vi.mock('../../../router', () => ({ default: { push: vi.fn() } }));
vi.mock('../../../layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const first = { id: 'first', name: 'First workspace', slug: 'first', created_at: '', updated_at: '' };
const second = { ...first, id: 'second', name: 'Second workspace', slug: 'second' };

function mountPage() {
    return mount(Index, { global: { stubs: { RouterLink: RouterLinkStub } } });
}

beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
});

it('offers creation when the user has no workspaces', () => {
    const wrapper = mountPage();

    expect(wrapper.get('h1').text()).toBe('Workspaces');
    expect(wrapper.text()).toContain('You have no workspaces yet.');
    expect(wrapper.findAll('li')).toHaveLength(0);
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'workspace-create' });
    expect(wrapper.getComponent(RouterLinkStub).text()).toBe('Create workspace');
});

it.each([[first], [first, second]])('lists every available workspace (%#)', (...organizations) => {
    useAuthStore().organizations = organizations;
    useOrganizationStore().syncFromAuth();
    const wrapper = mountPage();

    expect(wrapper.findAll('ul > li')).toHaveLength(organizations.length);
    for (const workspace of organizations) {
        expect(wrapper.text()).toContain(workspace.name);
    }
    expect(wrapper.text()).not.toContain('You have no workspaces yet.');
    expect(wrapper.findAll('[aria-current="true"]')).toHaveLength(1);
    expect(wrapper.get('[aria-current="true"]').text()).toContain('First workspace');
    expect(wrapper.get('[aria-current="true"]').text()).toContain('Current');
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'workspace-create' });
});

it('moves the current marker when the selected workspace changes', async () => {
    useAuthStore().organizations = [first, second];
    const organization = useOrganizationStore();
    organization.syncFromAuth();
    const wrapper = mountPage();

    organization.current = second;
    await nextTick();

    expect(wrapper.findAll('[aria-current="true"]')).toHaveLength(1);
    expect(wrapper.get('[aria-current="true"]').text()).toContain('Second workspace');
    expect(wrapper.findAll('li')[0].text()).not.toContain('Current');
});

it('updates the list from auth state without retaining removed workspaces', async () => {
    useAuthStore().organizations = [first];
    const wrapper = mountPage();

    useAuthStore().organizations = [second];
    await nextTick();

    expect(wrapper.findAll('li')).toHaveLength(1);
    expect(wrapper.get('li').text()).toContain('Second workspace');
    expect(wrapper.get('li').text()).not.toContain('First workspace');
    expect(wrapper.find('[aria-current="true"]').exists()).toBe(false);
});

// «Кобальт»: every workspace card that is not the current one offers to
// switch to it right there — the sidebar switcher is not the only way.
it('switches to a workspace from its card, and offers no switch on the current one', async () => {
    const auth = useAuthStore();
    auth.organizations = [first, second];
    const organization = useOrganizationStore();
    organization.current = first;
    const switchTo = vi.spyOn(organization, 'switchTo').mockImplementation(() => undefined as never);
    const wrapper = mountPage();

    expect(wrapper.get('[aria-current="true"]').find('[data-test="workspace-switch"]').exists()).toBe(false);
    await wrapper.get('[data-test="workspace-switch"]').trigger('click');

    expect(switchTo).toHaveBeenCalledWith('second');
});
