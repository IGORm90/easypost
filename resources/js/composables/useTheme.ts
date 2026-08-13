import { ref, watchEffect } from 'vue';

export type Theme = 'light' | 'dark';

const STORAGE_KEY = 'theme';

function systemPrefersDark(): boolean {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function readStoredTheme(): Theme | null {
    const stored = localStorage.getItem(STORAGE_KEY);

    return stored === 'light' || stored === 'dark' ? stored : null;
}

const theme = ref<Theme>(readStoredTheme() ?? (systemPrefersDark() ? 'dark' : 'light'));

// Keep the `dark` class on <html> and localStorage in sync with the ref,
// wherever it changes from (toggle() below, or a future settings page).
watchEffect(() => {
    document.documentElement.classList.toggle('dark', theme.value === 'dark');
    localStorage.setItem(STORAGE_KEY, theme.value);
});

function toggle(): void {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
}

/**
 * Global theme state shared across the whole app. Default is the stored
 * preference, falling back to the system `prefers-color-scheme`.
 */
export function useTheme() {
    return { theme, toggle };
}
