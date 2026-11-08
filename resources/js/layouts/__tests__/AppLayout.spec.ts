import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick, reactive } from 'vue';
import AppLayout from '../AppLayout.vue';
import { useAuthStore } from '../../stores/auth';

const route = reactive<{ name: string; meta: { navSection?: string } }>({ name: 'home', meta: {} });

vi.mock('vue-router', async (importOriginal) => ({
    ...await importOriginal<typeof import('vue-router')>(),
    useRoute: () => route,
}));

/**
 * Final review, Important 11: there was no navigation at all below the
 * `md` breakpoint. The sidebar is `hidden … md:flex` and the header held
 * only the org switcher, the theme toggle and Logout — so on a phone a
 * signed-in user could see whichever page they landed on and reach no
 * other one, with no way back.
 */
function mountLayout({ superadmin = false } = {}) {
    setActivePinia(createPinia());
    route.name = 'home';
    route.meta = {};
    // Task 1.7: the admin destination is the one entry the sidebar hides
    // from users who cannot reach it, so the session state has to be
    // seeded before mount rather than after.
    useAuthStore().isSuperadmin = superadmin;

    return mount(AppLayout, {
        global: {
            stubs: {
                RouterLink: { props: ['to'], template: '<a :data-route="to.name"><slot /></a>' },
                OrganizationSwitcher: true,
                ThemeToggle: true,
                LimitToast: true,
                ImpersonationBanner: true,
            },
        },
    });
}

/** Every destination the sidebar offers must be reachable on a phone too. */
const DESTINATIONS = ['home', 'channels', 'media', 'workspaces', 'settings-profile', 'settings-team', 'settings-sets', 'settings-signatures'];

function routeNames(wrapper: ReturnType<typeof mountLayout>, selector: string): string[] {
    // `a[data-route]`: only the SPA's own destinations. The monitoring links
    // are plain anchors to server-rendered pages and carry no data-route.
    return wrapper.findAll(`${selector} a[data-route]`).map((link) => link.attributes('data-route') ?? '');
}

/**
 * The menu is hidden with `v-show` (so the toggle's `aria-controls` has
 * something to point at while collapsed), which means it is present in
 * the DOM and hidden by `display: none`.
 *
 * Asserted on the inline style rather than through `isVisible()`: under
 * happy-dom that helper answers `true` for an element carrying
 * `style="display: none"`, so it would pass whether the menu were hidden
 * or not — a green light that means nothing. `display` is what actually
 * keeps the links out of the a11y tree and the tab order, so that is what
 * this checks.
 */
function expectMenuHidden(wrapper: ReturnType<typeof mountLayout>): void {
    expect((wrapper.get('[data-test="mobile-nav"]').element as HTMLElement).style.display).toBe('none');
}

it('keeps the sidebar hidden on small screens, as the layout intends', () => {
    const w = mountLayout();

    expect(w.get('aside').classes()).toContain('hidden');
    expect(w.get('aside').classes()).toContain('md:flex');
});

it('draws the sidebar on the dark navigation surface, as the Cobalt theme does', () => {
    const w = mountLayout();

    expect(w.get('aside').classes()).toContain('bg-nav');
    expect(w.get('aside').classes()).toContain('text-nav-text');
});

it('groups the settings destinations under their own heading in both menus', () => {
    const w = mountLayout();

    for (const menu of ['aside', '[data-test="mobile-nav"]']) {
        const group = w.get(`${menu} [data-test="nav-group-settings"]`);
        expect(group.get('p').text()).toBe('Settings');
        expect(group.findAll('a[data-route]').map((a) => a.attributes('data-route'))).toEqual([
            'settings-profile',
            'settings-team',
            'settings-sets',
            'settings-signatures',
        ]);
    }
});

it('offers a navigation toggle below the md breakpoint, where the sidebar is not rendered', () => {
    const w = mountLayout();

    const toggle = w.get('[data-test="mobile-nav-toggle"]');

    // Only below `md`: above it the sidebar already carries the same links.
    expect(toggle.classes()).toContain('md:hidden');
    expect(toggle.attributes('aria-expanded')).toBe('false');
});

// Final review round 2: the toggle announced a collapsed/expanded state
// but never said what it collapsed, so a screen-reader user was told
// "menu, collapsed" with no way to know what appears. `aria-controls`
// needs a target that EXISTS while collapsed, which is why the menu is
// hidden with `v-show` rather than removed with `v-if` — `display: none`
// still keeps it out of the a11y tree and the tab order.
it('points the toggle at the menu it controls, in both states', async () => {
    const w = mountLayout();

    const controls = w.get('[data-test="mobile-nav-toggle"]').attributes('aria-controls');

    expect(controls).toBeTruthy();
    expect(w.get('[data-test="mobile-nav"]').attributes('id')).toBe(controls);

    await w.get('[data-test="mobile-nav-toggle"]').trigger('click');

    expect(w.get('[data-test="mobile-nav"]').attributes('id')).toBe(controls);
});

it('reaches every sidebar destination from the mobile menu', async () => {
    const w = mountLayout();

    expectMenuHidden(w);

    await w.get('[data-test="mobile-nav-toggle"]').trigger('click');

    expect(w.get('[data-test="mobile-nav-toggle"]').attributes('aria-expanded')).toBe('true');
    expect(routeNames(w, '[data-test="mobile-nav"]')).toEqual(DESTINATIONS);
});

it('closes the mobile menu once a destination is chosen, so it does not cover the page it navigated to', async () => {
    const w = mountLayout();

    await w.get('[data-test="mobile-nav-toggle"]').trigger('click');
    await w.get('[data-test="mobile-nav"] a[data-route="workspaces"]').trigger('click');

    expectMenuHidden(w);
});

it.each(['workspaces', 'workspace-create'])('marks Workspaces active on %s in both menus and clears it on leaving', async (name) => {
    const wrapper = mountLayout();
    route.name = name;
    route.meta = { navSection: 'workspaces' };
    await nextTick();

    for (const menu of ['aside', '[data-test="mobile-nav"]']) {
        const link = wrapper.get(`${menu} a[data-route="workspaces"]`);
        expect(link.classes()).toContain('bg-nav-active');
        expect(link.classes()).toContain('text-on-nav');
    }

    route.name = 'home';
    route.meta = {};
    await nextTick();
    for (const menu of ['aside', '[data-test="mobile-nav"]']) {
        expect(wrapper.get(`${menu} a[data-route="workspaces"]`).classes()).not.toContain('bg-nav-active');
    }
});

/*
 * Task 1.7: /admin/users is the only route in the app gated by more than
 * a session. Offering the link to someone the router guard would
 * immediately turn around is worse than not offering it — and the link
 * has to appear in both renderings of the list, not just the sidebar,
 * for the same reason every other destination does.
 */
it('keeps the admin destination out of the way of users who cannot open it', async () => {
    const w = mountLayout();

    await w.get('[data-test="mobile-nav-toggle"]').trigger('click');

    expect(routeNames(w, 'aside')).toEqual(DESTINATIONS);
    expect(routeNames(w, '[data-test="mobile-nav"]')).toEqual(DESTINATIONS);
});

it('offers it to a superadmin, in the sidebar and on a phone alike', async () => {
    const w = mountLayout({ superadmin: true });

    await w.get('[data-test="mobile-nav-toggle"]').trigger('click');

    expect(routeNames(w, 'aside')).toEqual([...DESTINATIONS, 'admin-users']);
    expect(routeNames(w, '[data-test="mobile-nav"]')).toEqual([...DESTINATIONS, 'admin-users']);
});

/*
 * PR 7: /horizon, /pulse and /log-viewer are server-rendered pages outside
 * the SPA. They are plain anchors, never <router-link>s: the client router
 * has no such routes, and a router-link would resolve to the SPA catch-all
 * and render nothing. Like the admin entry they are only offered to a
 * platform admin — hiding is not the boundary, the `viewMonitoring` gate on
 * each page is — and, like every other destination, in both renderings of
 * the menu.
 */
const MONITORING_HREFS = ['/horizon', '/pulse', '/log-viewer'];

function monitoringLinks(wrapper: ReturnType<typeof mountLayout>, selector: string) {
    return wrapper.findAll(`${selector} [data-test="monitoring-link"]`);
}

it.each(['aside', '[data-test="mobile-nav"]'])('offers the three monitoring pages to a platform admin in %s', async (menu) => {
    const w = mountLayout({ superadmin: true });

    await w.get('[data-test="mobile-nav-toggle"]').trigger('click');

    const links = monitoringLinks(w, menu);
    expect(links.map((l) => l.attributes('href'))).toEqual(MONITORING_HREFS);

    for (const link of links) {
        expect(link.element.tagName).toBe('A');
        expect(link.attributes('data-route')).toBeUndefined();
        // A separate application in its own tab: the SPA keeps its place.
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toContain('noopener');
        // The new tab is announced, not only implied by the icon-less link.
        expect(link.find('.sr-only').exists()).toBe(true);
    }
    expect(w.find(`${menu} [data-test="monitoring-heading"]`).exists()).toBe(true);
});

it('labels each monitoring page by the question it answers, not just the product name', () => {
    const w = mountLayout({ superadmin: true });

    expect(monitoringLinks(w, 'aside').map((l) => l.text())).toEqual([
        expect.stringContaining('Queues'),
        expect.stringContaining('Performance'),
        expect.stringContaining('Logs'),
    ]);
});

it('shows no monitoring entry, heading or link to anyone who is not a platform admin', async () => {
    const w = mountLayout();

    await w.get('[data-test="mobile-nav-toggle"]').trigger('click');

    for (const menu of ['aside', '[data-test="mobile-nav"]']) {
        expect(monitoringLinks(w, menu)).toHaveLength(0);
        expect(w.find(`${menu} [data-test="monitoring-heading"]`).exists()).toBe(false);
    }
    expect(w.html()).not.toContain('/horizon');
    expect(w.html()).not.toContain('/log-viewer');
});
