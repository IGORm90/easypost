import { createPinia, setActivePinia } from 'pinia';

/**
 * The admin screen is the one route in this app that is not open to every
 * signed-in user (Task 1.7). The real gate is the `superadmin` middleware
 * on the API — this guard exists so that a user who cannot use the screen
 * is not shown a page that would only fill up with 403s.
 */
const get = vi.fn();
const post = vi.fn();

vi.mock('../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
    },
}));

function meResponds({ superadmin }: { superadmin: boolean }): void {
    get.mockImplementation((url: string) => {
        if (url === '/auth/me') {
            return Promise.resolve({
                data: {
                    user: {
                        id: 'u-1',
                        name: 'Dana Levin',
                        email: 'dana@example.test',
                        timezone: 'UTC',
                        locale: 'en',
                        email_verified_at: null,
                        created_at: '',
                        updated_at: '',
                    },
                    organizations: [],
                    is_superadmin: superadmin,
                    impersonator: null,
                },
            });
        }

        return Promise.resolve({ data: [] });
    });
}

beforeEach(() => {
    // A fresh module registry per test: the router is a singleton that
    // resolves its guard against whatever the store held on first
    // navigation, so reusing it across tests would answer the second
    // question with the first test's session.
    vi.resetModules();
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    localStorage.clear();
});

it('turns a signed-in user without the instance privilege away from the admin screen', async () => {
    meResponds({ superadmin: false });
    const { default: router } = await import('../router');

    await router.push('/admin/users');
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('home');
});

it('lets a superadmin through to it', async () => {
    meResponds({ superadmin: true });
    const { default: router } = await import('../router');

    await router.push('/admin/users');
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('admin-users');
});

it('sends a signed-out visitor to the login page, not to the calendar', async () => {
    get.mockRejectedValue(new Error('unauthenticated'));
    const { default: router } = await import('../router');

    await router.push('/admin/users');
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('login');
});

it('allows a signed-in user without any workspace to open workspace creation', async () => {
    meResponds({ superadmin: false });
    const { default: router } = await import('../router');
    await router.push('/workspaces/create');
    await router.isReady();
    expect(router.currentRoute.value.name).toBe('workspace-create');
});

it('requires a session to create a workspace', async () => {
    get.mockRejectedValue(new Error('unauthenticated'));
    const { default: router } = await import('../router');
    await router.push('/workspaces/create');
    await router.isReady();
    expect(router.currentRoute.value.name).toBe('login');
});

it('allows a signed-in user without any workspace to open the workspace list', async () => {
    meResponds({ superadmin: false });
    const { default: router } = await import('../router');
    await router.push('/workspaces');
    await router.isReady();
    expect(router.currentRoute.value.name).toBe('workspaces');
});

it('requires a session to view workspaces', async () => {
    get.mockRejectedValue(new Error('unauthenticated'));
    const { default: router } = await import('../router');
    await router.push('/workspaces');
    await router.isReady();
    expect(router.currentRoute.value.name).toBe('login');
});
