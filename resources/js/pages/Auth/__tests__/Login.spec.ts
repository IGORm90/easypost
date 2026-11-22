import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { createPinia, setActivePinia } from 'pinia';
import Login from '../Login.vue';

const { get, post, push, route } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    push: vi.fn(),
    route: { query: {} as Record<string, string | undefined> },
}));

function mountLogin() {
    return mount(Login, { global: { stubs: { RouterLink: true } } });
}

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
    },
}));

vi.mock('../../../router', () => ({
    default: { push },
}));

vi.mock('vue-router', async (importOriginal) => ({
    ...(await importOriginal<typeof import('vue-router')>()),
    useRoute: () => route,
    useRouter: () => ({ push }),
}));

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    push.mockReset();
    route.query = {};
});

it('starts Google sign-in and leaves this page for the authorization URL', async () => {
    get.mockResolvedValue({ data: { url: 'https://accounts.google.test/authorize?state=csrf-state' } });
    const assign = vi.spyOn(window.location, 'assign').mockImplementation(() => undefined);
    const wrapper = mountLogin();

    await wrapper.get('[data-test="google-login"]').trigger('click');
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/auth/oauth/google/redirect');
    expect(assign).toHaveBeenCalledWith('https://accounts.google.test/authorize?state=csrf-state');
});

it('shows a generic error when Google returns the user after an unsuccessful authorization', () => {
    route.query = { oauth_error: '1' };
    const wrapper = mountLogin();

    expect(wrapper.get('[data-test="oauth-error"]').attributes('role')).toBe('alert');
});

it('keeps the user on the login page when Google authorization cannot start', async () => {
    get.mockRejectedValue(new Error('network unavailable'));
    const wrapper = mountLogin();

    await wrapper.get('[data-test="google-login"]').trigger('click');
    await flushPromises();

    expect(wrapper.get('[data-test="login-error"]').attributes('role')).toBe('alert');
    expect((wrapper.get('[data-test="google-login"]').element as HTMLButtonElement).disabled).toBe(false);
});

it('prevents duplicate Google authorization requests while the redirect URL is loading', async () => {
    let rejectRequest: (reason?: unknown) => void = () => undefined;
    get.mockImplementation(
        () => new Promise((_, reject: (reason?: unknown) => void) => {
            rejectRequest = reject;
        }),
    );
    const wrapper = mountLogin();
    const button = wrapper.get('[data-test="google-login"]');

    await button.trigger('click');
    await nextTick();

    expect((button.element as HTMLButtonElement).disabled).toBe(true);
    await button.trigger('click');
    // Counted by URL: mounting the page also asks /auth/registration once.
    expect(get.mock.calls.filter(([url]) => url === '/auth/oauth/google/redirect')).toHaveLength(1);

    rejectRequest(new Error('network unavailable'));
    await flushPromises();
});

// Registration can be closed on the server (REGISTRATION_ENABLED=false). The
// login screen then must not advertise a door that answers 403.
it('offers the register link while registration is open', async () => {
    get.mockResolvedValue({ data: { enabled: true } });
    const wrapper = mountLogin();
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/auth/registration');
    expect(wrapper.find('[data-test="register-prompt"]').exists()).toBe(true);
});

it('hides the register link once the API says registration is closed', async () => {
    get.mockResolvedValue({ data: { enabled: false } });
    const wrapper = mountLogin();
    await flushPromises();

    expect(wrapper.find('[data-test="register-prompt"]').exists()).toBe(false);
});

it('keeps the register link when the status cannot be loaded; the server still refuses', async () => {
    get.mockRejectedValue(new Error('network'));
    const wrapper = mountLogin();
    await flushPromises();

    expect(wrapper.find('[data-test="register-prompt"]').exists()).toBe(true);
});
