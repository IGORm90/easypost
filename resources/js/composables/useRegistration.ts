import { computed, ref } from 'vue';
import { api } from '../api/client';

/**
 * Whether the server currently accepts NEW accounts (REGISTRATION_ENABLED).
 *
 * `open` is true unless the server said otherwise: if the status request fails
 * we keep showing the form rather than hiding sign-up on a flaky network, since
 * the server refuses a closed registration itself (403) and `markClosed()`
 * turns that refusal into the same notice.
 */
export function useRegistration() {
    const enabled = ref<boolean | null>(null);
    const open = computed(() => enabled.value !== false);

    async function load(): Promise<void> {
        try {
            const { data } = await api.get<{ enabled?: boolean }>('/auth/registration');
            enabled.value = data?.enabled !== false;
        } catch {
            enabled.value = null;
        }
    }

    function markClosed(): void {
        enabled.value = false;
    }

    return { open, load, markClosed };
}
