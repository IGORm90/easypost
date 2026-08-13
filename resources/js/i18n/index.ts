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

export const i18n = createI18n({
    legacy: false,
    locale: detectLocale(),
    fallbackLocale: 'ru',
    messages: { ru, en },
});
