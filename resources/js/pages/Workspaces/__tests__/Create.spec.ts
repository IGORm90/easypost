import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import Create from '../Create.vue';
import { useAuthStore } from '../../../stores/auth';
import { useOrganizationStore } from '../../../stores/organization';
import en from '../../../i18n/en.json';

const post = vi.fn();
const replace = vi.fn();

vi.mock('../../../api/client', () => ({ api: { post: (...args: unknown[]) => post(...args) } }));
vi.mock('../../../router', () => ({ default: { push: vi.fn() } }));
vi.mock('vue-router', async (importOriginal) => ({
    ...await importOriginal<typeof import('vue-router')>(),
    useRouter: () => ({ replace }),
}));
vi.mock('../../../layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const original = { id: 'first', name: 'First', slug: 'first', created_at: '', updated_at: '' };
const created = { ...original, id: 'second', name: 'Second workspace', slug: 'second' };

beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
    post.mockReset().mockResolvedValue({ data: created });
    replace.mockReset().mockResolvedValue(undefined);
    useAuthStore().organizations = [original];
    useOrganizationStore().syncFromAuth();
});

it('trims the name, creates and selects the workspace before opening its calendar', async () => {
    const wrapper = mount(Create);
    replace.mockImplementation(async () => {
        expect(useOrganizationStore().current?.id).toBe('second');
        expect(localStorage.getItem('organization_id')).toBe('second');
    });

    await wrapper.get('input').setValue('  Second workspace  ');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(post).toHaveBeenCalledExactlyOnceWith('/organizations', { name: 'Second workspace' });
    expect(useAuthStore().organizations).toEqual([original, created]);
    expect(replace).toHaveBeenCalledExactlyOnceWith({ name: 'home' });
});

it.each(['', '   ', 'a'.repeat(256)])('rejects invalid names without a request (%#)', async (name) => {
    const wrapper = mount(Create);
    await wrapper.get('input').setValue(name);
    await wrapper.get('form').trigger('submit');

    expect(post).not.toHaveBeenCalled();
    expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
    expect(replace).not.toHaveBeenCalled();
});

it('allows a 255-character name', async () => {
    const wrapper = mount(Create);
    await wrapper.get('input').setValue('a'.repeat(255));
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/organizations', { name: 'a'.repeat(255) });
});

it('blocks repeated submissions and cancellation while the request is pending', async () => {
    let resolve!: (value: { data: typeof created }) => void;
    post.mockReturnValueOnce(new Promise((done) => { resolve = done; }));
    const wrapper = mount(Create);
    await wrapper.get('input').setValue('Second workspace');
    await wrapper.get('form').trigger('submit');
    await wrapper.get('form').trigger('submit');

    expect(post).toHaveBeenCalledTimes(1);
    expect(wrapper.get('input').attributes('disabled')).toBeDefined();
    expect(wrapper.findAll('button').every((button) => button.attributes('disabled') !== undefined)).toBe(true);
    expect(replace).not.toHaveBeenCalled();
    resolve({ data: created });
    await flushPromises();
});

it('cancels without creating or switching the workspace', async () => {
    const wrapper = mount(Create);
    await wrapper.get('button[type="button"]').trigger('click');
    await flushPromises();
    expect(post).not.toHaveBeenCalled();
    expect(useOrganizationStore().current).toEqual(original);
    expect(replace).toHaveBeenCalledWith({ name: 'workspaces' });
});

it.each(['rejected', 'aborted'])('retains the form when cancellation navigation is %s', async (failure) => {
    if (failure === 'rejected') {
        replace.mockRejectedValueOnce(new Error('navigation unavailable'));
    } else {
        replace.mockResolvedValueOnce(new Error('navigation aborted'));
    }
    const wrapper = mount(Create);
    await wrapper.get('input').setValue('Unsaved workspace');
    await wrapper.get('button[type="button"]').trigger('click');
    await flushPromises();

    expect(replace).toHaveBeenCalledWith({ name: 'workspaces' });
    expect(wrapper.get('[role="alert"]').text()).toBe(en.organization.create.error);
    expect(wrapper.get('input').element.value).toBe('Unsaved workspace');
    expect(useOrganizationStore().current).toEqual(original);
    expect(post).not.toHaveBeenCalled();

    await wrapper.get('button[type="button"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    expect(replace).toHaveBeenCalledTimes(2);
    expect(post).not.toHaveBeenCalled();
});

it('shows a server name error, retains the input and permits correction', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true, response: { status: 422, data: { errors: { name: ['Choose a name.'] } } } });
    const wrapper = mount(Create);
    await wrapper.get('input').setValue('Second workspace');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Choose a name.');
    expect(wrapper.get('input').element.value).toBe('Second workspace');
    expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
    expect(useOrganizationStore().current).toEqual(original);
    expect(replace).not.toHaveBeenCalled();

    await wrapper.get('input').setValue('Corrected');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(post).toHaveBeenLastCalledWith('/organizations', { name: 'Corrected' });
});

it.each([
    { error: { isAxiosError: true }, message: 'networkError' as const },
    { error: { isAxiosError: true, response: { status: 500 } }, message: 'error' as const },
])('shows $message without clearing the input or changing workspace', async ({ error, message }) => {
    post.mockRejectedValueOnce(error);
    const wrapper = mount(Create);
    await wrapper.get('input').setValue('Second workspace');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toBe(en.organization.create[message]);
    expect(wrapper.get('input').element.value).toBe('Second workspace');
    expect(useOrganizationStore().current).toEqual(original);
    expect(post).toHaveBeenCalledTimes(1);
    expect(replace).not.toHaveBeenCalled();
});

it.each(['rejected', 'aborted'])('retries only navigation after a %s transition, never creation', async (failure) => {
    if (failure === 'rejected') {
        replace.mockRejectedValueOnce(new Error('navigation unavailable'));
    } else {
        replace.mockResolvedValueOnce(new Error('navigation aborted'));
    }
    const wrapper = mount(Create);
    await wrapper.get('input').setValue('Second workspace');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toBe(en.organization.create.navigationError);
    expect(wrapper.find('form').exists()).toBe(false);
    expect(useOrganizationStore().current).toEqual(created);

    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(replace).toHaveBeenCalledTimes(2);
    expect(post).toHaveBeenCalledTimes(1);
});
