import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import ImpersonationBanner from '../ImpersonationBanner.vue';
import en from '../../../i18n/en.json';
import { useAuthStore } from '../../../stores/auth';

/**
 * The banner exists because impersonation is otherwise invisible: the
 * session cookie is swapped server-side and every screen simply renders
 * as the other person. Without a standing reminder it is genuinely easy
 * to keep working — and posting — as someone else by accident.
 */
const post = vi.fn();
const restartSession = vi.fn();

vi.mock('../../../api/client', () => ({
    api: { post: (...args: unknown[]) => post(...args) },
}));

vi.mock('../../../utils/session', () => ({
    restartSession: () => restartSession(),
}));

beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    restartSession.mockReset();
});

function mountBanner() {
    return mount(ImpersonationBanner);
}

it('stays out of the way of an ordinary session', () => {
    const w = mountBanner();

    expect(w.find('[data-test="impersonation-banner"]').exists()).toBe(false);
});

it('names whose account is being borrowed, in words and not only in colour', () => {
    const auth = useAuthStore();
    auth.impersonator = { id: 'u-2', name: 'Robin Ash' };
    auth.user = { id: 'u-1', name: 'Dana Levin' } as never;

    const w = mountBanner();

    expect(w.get('[data-test="impersonation-banner"]').text()).toContain('Dana Levin');
    // color.md §4: the meaning has to survive without the tint, so the
    // banner carries an icon and a sentence, not a coloured bar alone.
    expect(w.find('[data-test="impersonation-banner"] svg').exists()).toBe(true);
});

it('ends the session and restarts the app, because the cookie was swapped underneath it', async () => {
    const auth = useAuthStore();
    auth.impersonator = { id: 'u-2', name: 'Robin Ash' };
    auth.user = { id: 'u-1', name: 'Dana Levin' } as never;
    post.mockResolvedValue({ data: {} });

    const w = mountBanner();
    await w.get('[data-test="impersonation-stop"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/admin/impersonate/stop');
    expect(restartSession).toHaveBeenCalled();
});

it('says so when stopping fails, instead of leaving the button looking inert', async () => {
    const auth = useAuthStore();
    auth.impersonator = { id: 'u-2', name: 'Robin Ash' };
    auth.user = { id: 'u-1', name: 'Dana Levin' } as never;
    post.mockRejectedValue(Object.assign(new Error('boom'), { isAxiosError: true, response: { status: 500 } }));

    const w = mountBanner();
    await w.get('[data-test="impersonation-stop"]').trigger('click');
    await flushPromises();

    expect(w.text()).toContain(en.admin.impersonating.stopError);
    expect(restartSession).not.toHaveBeenCalled();
});

/*
 * ImpersonationService::stop() invalidates the whole session when the
 * account that started the impersonation has since been deleted — there
 * is nobody left to return to. Retrying is pointless and staying put
 * means sitting on a dead session, so this one case goes to the login
 * page instead of showing an error next to a button that cannot work.
 */
it('sends the user to sign in again when the account they came from is gone', async () => {
    const auth = useAuthStore();
    auth.impersonator = { id: 'u-2', name: 'Robin Ash' };
    auth.user = { id: 'u-1', name: 'Dana Levin' } as never;
    post.mockRejectedValue(
        Object.assign(new Error('gone'), {
            isAxiosError: true,
            response: { status: 403, data: { message: 'The impersonating account no longer exists.' } },
        }),
    );

    const w = mountBanner();
    await w.get('[data-test="impersonation-stop"]').trigger('click');
    await flushPromises();

    // No error message: the reload lands on the login page, so a toast
    // here would be text nobody can read.
    expect(w.text()).not.toContain(en.admin.impersonating.stopError);
    expect(restartSession).toHaveBeenCalled();
});
