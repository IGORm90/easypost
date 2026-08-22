import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import Users from '../Users.vue';
import en from '../../../i18n/en.json';

/**
 * The admin user list (Task 1.7). Impersonation shipped in Task 10 with
 * nothing to point it at — a superadmin had to already know a user id and
 * POST to the endpoint by hand. These specs pin what the screen has to
 * get right:
 *
 *  - Starting an impersonation swaps the session cookie server-side, so
 *    the app has to restart afterwards rather than patch its own state.
 *  - The failure modes are several and mean different things (403 for a
 *    nested session, 422 for your own account), so they get separate
 *    messages instead of one shrug.
 */
const get = vi.fn();
const post = vi.fn();
const restartSession = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
    },
}));

vi.mock('../../../utils/session', () => ({
    restartSession: () => restartSession(),
}));

vi.mock('../../../layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

interface Row {
    id: string;
    name: string;
    email: string;
    is_superadmin: boolean;
    email_verified_at: string | null;
    created_at: string;
    organizations: string[];
}

function row(overrides: Partial<Row> = {}): Row {
    return {
        id: 'u-1',
        name: 'Dana Levin',
        email: 'dana@example.test',
        is_superadmin: false,
        email_verified_at: '2026-08-01T00:00:00.000000Z',
        created_at: '2026-08-01T00:00:00.000000Z',
        organizations: ['Newsroom'],
        ...overrides,
    };
}

function respondWith(rows: Row[], meta: Partial<{ current_page: number; last_page: number }> = {}): void {
    get.mockResolvedValue({
        data: { data: rows, meta: { current_page: 1, last_page: 1, ...meta } },
    });
}

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    restartSession.mockReset();
    respondWith([row()]);
});

/** The search box waits for a pause in typing — see the debounce spec below. */
async function waitForDebounce() {
    await new Promise((resolve) => setTimeout(resolve, 300));
    await flushPromises();
}

async function mountPage() {
    const w = mount(Users);
    await flushPromises();

    return w;
}

it('lists the accounts on the instance with the workspaces they belong to', async () => {
    const w = await mountPage();

    expect(get).toHaveBeenCalledWith('/admin/users', { params: { page: 1, search: undefined } });
    expect(w.text()).toContain('Dana Levin');
    expect(w.text()).toContain('dana@example.test');
    expect(w.text()).toContain('Newsroom');
});

it('says so when an account belongs to no workspace, rather than leaving the cell blank', async () => {
    respondWith([row({ organizations: [] })]);
    const w = await mountPage();

    expect(w.text()).toContain(en.admin.users.noOrganizations);
});

it('marks the other superadmins, since they are who this screen is about', async () => {
    respondWith([row({ is_superadmin: true })]);
    const w = await mountPage();

    expect(w.text()).toContain(en.admin.users.superadmin);
});

it('searches the server rather than filtering the page it already has', async () => {
    const w = await mountPage();
    get.mockClear();
    respondWith([row({ id: 'u-2', name: 'Sam Okoro', email: 'sam@example.test' })]);

    await w.get('[data-test="admin-user-search"] input').setValue('Sam');
    await waitForDebounce();

    expect(get).toHaveBeenCalledWith('/admin/users', { params: { page: 1, search: 'Sam' } });
    expect(w.text()).toContain('Sam Okoro');
    expect(w.text()).not.toContain('Dana Levin');
});

// Found in the browser check: typing "demo@" fired six requests, one per
// keystroke. The list is server-side searched, so every character was a
// full paginated query — and the answers could arrive out of order,
// leaving the table showing the results for a prefix of what is in the box.
it('waits for a pause in typing instead of querying on every keystroke', async () => {
    vi.useFakeTimers();

    try {
        const w = mount(Users);
        await vi.advanceTimersByTimeAsync(0);
        await flushPromises();
        get.mockClear();

        const box = w.get('[data-test="admin-user-search"] input');
        await box.setValue('de');
        await box.setValue('dem');
        await box.setValue('demo');

        expect(get).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith('/admin/users', { params: { page: 1, search: 'demo' } });
    } finally {
        vi.useRealTimers();
    }
});

it('explains an empty result instead of showing a bare table', async () => {
    respondWith([]);
    const w = await mountPage();

    expect(w.text()).toContain(en.admin.users.empty);
    expect(w.text()).toContain(en.admin.users.emptyHint);
});

it('offers the next page only when there is one', async () => {
    respondWith([row()], { current_page: 1, last_page: 1 });
    const w = await mountPage();

    expect(w.find('[data-test="admin-users-more"]').exists()).toBe(false);
});

it('appends the next page rather than replacing the rows already on screen', async () => {
    respondWith([row()], { current_page: 1, last_page: 2 });
    const w = await mountPage();

    respondWith([row({ id: 'u-2', name: 'Sam Okoro' })], { current_page: 2, last_page: 2 });
    await w.get('[data-test="admin-users-more"]').trigger('click');
    await flushPromises();

    expect(get).toHaveBeenLastCalledWith('/admin/users', { params: { page: 2, search: undefined } });
    expect(w.text()).toContain('Dana Levin');
    expect(w.text()).toContain('Sam Okoro');
});

it('restarts the app after starting an impersonation, because the session cookie was swapped', async () => {
    post.mockResolvedValue({ data: {} });
    const w = await mountPage();

    await w.get('[data-test="admin-user-impersonate"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/admin/impersonate/u-1');
    expect(restartSession).toHaveBeenCalled();
});

it('says plainly that you cannot impersonate yourself', async () => {
    post.mockRejectedValue(
        Object.assign(new Error('unprocessable'), { isAxiosError: true, response: { status: 422, data: {} } }),
    );
    const w = await mountPage();

    await w.get('[data-test="admin-user-impersonate"]').trigger('click');
    await flushPromises();

    expect(w.text()).toContain(en.admin.errors.self);
    expect(restartSession).not.toHaveBeenCalled();
});

it('distinguishes a nested impersonation from a plain refusal', async () => {
    post.mockRejectedValue(
        Object.assign(new Error('forbidden'), {
            isAxiosError: true,
            response: { status: 403, data: { message: 'Cannot start a new impersonation session from within one.' } },
        }),
    );
    const w = await mountPage();

    await w.get('[data-test="admin-user-impersonate"]').trigger('click');
    await flushPromises();

    expect(w.text()).toContain(en.admin.errors.nested);
    expect(w.text()).not.toContain(en.admin.errors.forbidden);
});

it('reports a failed load instead of an empty table that reads as "no users"', async () => {
    get.mockRejectedValue(new Error('network'));
    const w = await mountPage();

    expect(w.text()).toContain(en.admin.users.loadError);
    expect(w.text()).not.toContain(en.admin.users.empty);
});
