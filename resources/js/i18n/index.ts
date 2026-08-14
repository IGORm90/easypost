import { createI18n } from 'vue-i18n';
import ru from './ru.json';
import en from './en.json';

const SUPPORTED_LOCALES = ['ru', 'en'] as const;
type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

function detectLocale(): SupportedLocale {
    const browserLocale = navigator.language.slice(0, 2);

    return (SUPPORTED_LOCALES as readonly string[]).includes(browserLocale)
        ? (browserLocale as SupportedLocale)
        : 'ru';
}

/**
 * Applies the locale the server holds for the signed-in user (see
 * UserResource::locale), called from the auth store whenever session
 * state arrives. Until this existed, `detectLocale()`'s browser guess was
 * the app's locale forever: a user on an English-locale browser who set
 * their profile to Russian saw Russian only until the next page load.
 * An unknown value is ignored rather than applied blindly.
 */
export function applyUserLocale(locale: string | null | undefined): void {
    if (typeof locale === 'string' && (SUPPORTED_LOCALES as readonly string[]).includes(locale)) {
        i18n.global.locale.value = locale as SupportedLocale;
    }
}

export const i18n = createI18n({
    legacy: false,
    locale: detectLocale(),
    fallbackLocale: 'ru',
    messages: { ru, en },
});
