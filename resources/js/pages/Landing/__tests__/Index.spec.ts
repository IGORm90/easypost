import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import Landing from '../Index.vue';
import { useAuthStore } from '../../../stores/auth';
import { i18n } from '../../../i18n';

/**
 * The public landing page at `/` (design variant A, «Кобальт — календарь
 * в герое»). It is the one page in the SPA that a signed-out visitor and
 * a signed-in member both see, so the only thing that changes between
 * them is where the header sends them.
 */
function mountLanding({ signedIn = false } = {}) {
    setActivePinia(createPinia());

    if (signedIn) {
        useAuthStore().user = {
            id: 'u-1',
            name: 'Dana Levin',
            email: 'dana@example.test',
            timezone: 'UTC',
            locale: 'ru',
            email_verified_at: null,
            created_at: '',
            updated_at: '',
        };
    }

    // No router is installed and none is stubbed: the landing page must not
    // depend on one. In production it is served from its own host, where a
    // client-side route to /login would render the form on a host that has no
    // session. Its calls to action are plain links, which Caddy sends on to the
    // app host.
    return mount(Landing);
}

/** color.md §3.1 counts a resting tint, not the focus ring. */
function restingPrimary(classes: string[]): string[] {
    return classes.filter((name) => /^(?:bg|text|border)-primary/.test(name));
}

beforeEach(() => {
    i18n.global.locale.value = 'ru';
});

it('leads with the product promise as the page heading', () => {
    const w = mountLanding();

    const h1 = w.findAll('h1');

    expect(h1).toHaveLength(1);
    expect(h1[0].text()).toBe(i18n.global.t('landing.hero.title'));
});

it('offers a signed-out visitor sign-in and sign-up, and sends the main call to action to sign-up', () => {
    const w = mountLanding();

    const header = w.get('header');
    expect(header.find('a[href="/login"]').exists()).toBe(true);
    expect(header.find('a[href="/register"]').exists()).toBe(true);
    expect(header.find('a[href="/calendar"]').exists()).toBe(false);

    expect(w.get('[data-test="hero-cta"]').attributes('href')).toBe('/register');
    expect(w.get('[data-test="final-cta"]').attributes('href')).toBe('/register');
});

it('sends a signed-in member to their calendar instead of the auth forms', () => {
    const w = mountLanding({ signedIn: true });

    const header = w.get('header');
    expect(header.find('a[href="/calendar"]').exists()).toBe(true);
    expect(header.find('a[href="/login"]').exists()).toBe(false);
    expect(header.find('a[href="/register"]').exists()).toBe(false);

    expect(w.get('[data-test="hero-cta"]').attributes('href')).toBe('/calendar');
    expect(w.get('[data-test="final-cta"]').attributes('href')).toBe('/calendar');
});

it('keeps the header out of the primary budget so the hero call to action is the one blue element on screen', () => {
    const w = mountLanding();

    const header = w.get('header');
    const tinted = header.findAll('*').flatMap((el) => restingPrimary(el.classes()));

    expect(tinted).toEqual([]);
    expect(restingPrimary(w.get('[data-test="hero-cta"]').classes())).toContain('bg-primary');
});

it('links every header section to a section that exists on the page', () => {
    const w = mountLanding();

    const anchors = w.get('header nav').findAll('a').map((a) => a.attributes('href'));

    expect(anchors).toEqual(['#features', '#how', '#statuses', '#teams']);
    for (const href of anchors) {
        expect(w.find(`section${href}`).exists()).toBe(true);
    }
});

it('shows every post state the calendar can show, labelled with the calendar\'s own wording', () => {
    const w = mountLanding();

    const statuses = w.get('section#statuses').text();

    for (const state of ['DRAFT', 'SCHEDULED', 'QUEUE', 'PUBLISHED', 'ERROR']) {
        expect(statuses).toContain(i18n.global.t(`calendar.state.${state}`));
    }
});

it('describes the calendar mock-up for assistive technology rather than reading out its grid', () => {
    const w = mountLanding();

    const mock = w.get('[data-test="hero-mockup"]');

    expect(mock.attributes('role')).toBe('img');
    expect(mock.attributes('aria-label')).toBe(i18n.global.t('landing.mockup.label'));
});

it('renders in English as well', () => {
    i18n.global.locale.value = 'en';
    const w = mountLanding();

    expect(w.get('h1').text()).toBe(i18n.global.t('landing.hero.title'));
    expect(w.get('h1').text()).not.toBe('landing.hero.title');
});

it('keeps the light palette of the design even when the app is in the dark theme', () => {
    document.documentElement.classList.add('dark');
    const w = mountLanding();

    expect(w.classes()).toContain('theme-light');
    document.documentElement.classList.remove('dark');
});

it('labels the mock-up\'s cards with the short wording the narrow day cells fit', () => {
    const w = mountLanding();

    const mock = w.get('[data-test="hero-mockup"]').text();

    expect(mock).toContain(i18n.global.t('landing.mockup.states.ERROR'));
    expect(mock).not.toContain(i18n.global.t('calendar.state.ERROR'));
});
