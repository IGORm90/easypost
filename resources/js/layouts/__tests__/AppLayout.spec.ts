import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import AppLayout from '../AppLayout.vue';

/**
 * Final review, Important 11: there was no navigation at all below the
 * `md` breakpoint. The sidebar is `hidden … md:flex` and the header held
 * only the org switcher, the theme toggle and Logout — so on a phone a
 * signed-in user could see whichever page they landed on and reach no
 * other one, with no way back.
 */
function mountLayout() {
    setActivePinia(createPinia());

    return mount(AppLayout, {
        global: {
            stubs: {
                RouterLink: { props: ['to'], template: '<a :data-route="to.name"><slot /></a>' },
                OrganizationSwitcher: true,
                ThemeToggle: true,
                LimitToast: true,
            },
        },
    });
}

/** Every destination the sidebar offers must be reachable on a phone too. */
const DESTINATIONS = ['home', 'channels', 'media', 'settings-profile', 'settings-team'];

function routeNames(wrapper: ReturnType<typeof mountLayout>, selector: string): string[] {
    return wrapper.findAll(`${selector} a`).map((link) => link.attributes('data-route') ?? '');
}

it('keeps the sidebar hidden on small screens, as the layout intends', () => {
    const w = mountLayout();

    expect(w.get('aside').classes()).toContain('hidden');
    expect(w.get('aside').classes()).toContain('md:flex');
});

it('offers a navigation toggle below the md breakpoint, where the sidebar is not rendered', () => {
    const w = mountLayout();

    const toggle = w.get('[data-test="mobile-nav-toggle"]');

    // Only below `md`: above it the sidebar already carries the same links.
    expect(toggle.classes()).toContain('md:hidden');
    expect(toggle.attributes('aria-expanded')).toBe('false');
});

it('reaches every sidebar destination from the mobile menu', async () => {
    const w = mountLayout();

    expect(w.find('[data-test="mobile-nav"]').exists()).toBe(false);

    await w.get('[data-test="mobile-nav-toggle"]').trigger('click');

    expect(w.get('[data-test="mobile-nav-toggle"]').attributes('aria-expanded')).toBe('true');
    expect(routeNames(w, '[data-test="mobile-nav"]')).toEqual(DESTINATIONS);
});

it('closes the mobile menu once a destination is chosen, so it does not cover the page it navigated to', async () => {
    const w = mountLayout();

    await w.get('[data-test="mobile-nav-toggle"]').trigger('click');
    await w.get('[data-test="mobile-nav"] a').trigger('click');

    expect(w.find('[data-test="mobile-nav"]').exists()).toBe(false);
});
