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
    expect(get).toHaveBeenCalledTimes(1);

    rejectRequest(new Error('network unavailable'));
    await flushPromises();
});
