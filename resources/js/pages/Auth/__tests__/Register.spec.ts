import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import Register from '../Register.vue';

const { get, post, push } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    push: vi.fn(),
}));

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
    },
}));

vi.mock('vue-router', async (importOriginal) => ({
    ...(await importOriginal<typeof import('vue-router')>()),
    useRoute: () => ({ query: {} }),
    useRouter: () => ({ push }),
}));

function mountRegister() {
    return mount(Register, { global: { stubs: { RouterLink: true } } });
}

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    push.mockReset();
});

it('shows the sign-up form while registration is open', async () => {
    get.mockResolvedValue({ data: { enabled: true } });
    const wrapper = mountRegister();
    await flushPromises();

    expect(wrapper.find('form').exists()).toBe(true);
    expect(wrapper.find('[data-test="registration-closed"]').exists()).toBe(false);
});

it('replaces the form with a notice when registration is closed', async () => {
    get.mockResolvedValue({ data: { enabled: false } });
    const wrapper = mountRegister();
    await flushPromises();

    expect(wrapper.find('form').exists()).toBe(false);
    expect(wrapper.get('[data-test="registration-closed"]').attributes('role')).toBe('status');
});

it('still shows the form when the status cannot be loaded; the server will refuse a closed door itself', async () => {
    get.mockRejectedValue(new Error('network'));
    const wrapper = mountRegister();
    await flushPromises();

    expect(wrapper.find('form').exists()).toBe(true);
});

it('switches to the closed notice when the submit itself is refused with 403', async () => {
    get.mockResolvedValue({ data: { enabled: true } });
    post.mockRejectedValue({ isAxiosError: true, response: { status: 403, data: {} } });
    const wrapper = mountRegister();
    await flushPromises();

    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(wrapper.find('[data-test="registration-closed"]').exists()).toBe(true);
});
