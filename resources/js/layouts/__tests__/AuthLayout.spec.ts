import { mount } from '@vue/test-utils';
import AuthLayout from '../AuthLayout.vue';
import { i18n } from '../../i18n';

/**
 * «Кобальт» gives the signed-out screens a split layout: the product's
 * pitch on the dark navigation surface beside the form. The pitch is
 * decorative context, so it must never push the form out of reach — the
 * form is the page's main landmark and the panel is hidden below `lg`.
 */
function mountLayout() {
    i18n.global.locale.value = 'en';

    return mount(AuthLayout, {
        slots: { default: '<form data-test="form" />' },
        global: { plugins: [i18n], stubs: { ThemeToggle: true } },
    });
}

it('renders the form inside the main landmark', () => {
    expect(mountLayout().find('main [data-test="form"]').exists()).toBe(true);
});

it('pitches the product on the navigation surface, beside the form on wide screens only', () => {
    const panel = mountLayout().get('[data-test="auth-brand"]');

    expect(panel.classes()).toEqual(expect.arrayContaining(['bg-nav', 'hidden', 'lg:flex']));
    expect(panel.text()).toContain(i18n.global.t('auth.brand.title'));
});
