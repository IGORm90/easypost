<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import AppLayout from '../../layouts/AppLayout.vue';
import Button from '../../components/ui/Button.vue';
import { useIntegrationsStore } from '../../stores/integrations';

/**
 * The frontend half of Task 13's `INTEGRATIONS_REDIRECT_URL` contract
 * (`config('services.integrations_redirect_url')`, default
 * `.../integrations/callback`): every OAuth provider's consent screen
 * redirects the browser back here. Never reached by a manual-auth
 * provider (Telegram, Bluesky) — those submit straight to `callback()`
 * from ConnectProviderModal without ever navigating away.
 *
 * `extra` round-trips generically: ConnectProviderModal stashed whatever
 * `POST .../auth-url` returned in `extra` (Mastodon's `{ instance }`;
 * empty for providers that need nothing) into
 * `sessionStorage['channel_connect_pending']` right before the redirect.
 * This component never inspects what's inside it — it's spread into the
 * callback payload untouched, alongside the provider's own query
 * parameters (`code`, ...), so a provider needing a brand new `extra`
 * key tomorrow needs no change here.
 *
 * Three distinct failure paths, per this task's brief, each shown as
 * words — never a raw server message, never a blank page:
 *  - the provider denied consent / reported its own error (`?error=...`
 *    on the redirect — the OAuth2 standard shape);
 *  - `pending` is missing or its `state` doesn't match the redirect's
 *    (expired session, reused/forged link, or this page opened without
 *    ever starting a connect flow);
 *  - the backend rejected the callback itself (expired/consumed
 *    `state`, provider-side auth failure, ...) — caught generically,
 *    never displaying `e.response.data.message` raw to the user.
 */
interface PendingConnect {
    provider: string;
    state: string;
    extra: Record<string, unknown>;
}

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useIntegrationsStore();

const status = ref<'pending' | 'success' | 'error'>('pending');
const errorMessage = ref('');
const connectedName = ref('');
// Task 36 fix round 1, Minor 5: true only when the callback failed with a
// 402 — the global warning toast (api/client.ts's interceptor) already
// surfaces that event, so this page's own local --danger alert must stay
// hidden for it (color.md §3.8), even though `status` still moves to
// 'error' so the "Back to channels" button appears exactly as it does
// for every other terminal failure.
const limitExceeded = ref(false);

function firstString(value: unknown): string | undefined {
    if (typeof value === 'string') {
        return value;
    }

    if (Array.isArray(value) && typeof value[0] === 'string') {
        return value[0];
    }

    return undefined;
}

function fail(message: string): void {
    status.value = 'error';
    errorMessage.value = message;
}

onMounted(async () => {
    const raw = sessionStorage.getItem('channel_connect_pending');
    // Consumed on first read regardless of outcome: a stale entry must
    // never be reused by a later, unrelated navigation to this route.
    sessionStorage.removeItem('channel_connect_pending');

    // `null` is a real outcome here (no entry, or unparseable JSON), so
    // the type says so rather than being cast away — the guard below
    // already handles it, and the cast only hid that from the compiler.
    let pending: PendingConnect | null;

    try {
        pending = raw ? JSON.parse(raw) : null;
    } catch {
        pending = null;
    }

    if (!pending || !pending.state || !pending.provider) {
        fail(t('integrations.callback.errors.invalidState'));

        return;
    }

    if (firstString(route.query.error)) {
        fail(t('integrations.callback.errors.denied'));

        return;
    }

    const returnedState = firstString(route.query.state);

    if (!returnedState || returnedState !== pending.state) {
        fail(t('integrations.callback.errors.invalidState'));

        return;
    }

    const forwarded: Record<string, unknown> = { ...pending.extra };

    for (const [key, value] of Object.entries(route.query)) {
        if (key === 'state') {
            continue;
        }

        const str = firstString(value);

        if (str !== undefined) {
            forwarded[key] = str;
        }
    }

    try {
        const integration = await store.completeCallback(pending.provider, { state: pending.state, ...forwarded });
        status.value = 'success';
        connectedName.value = integration.name;
    } catch (e) {
        if (isAxiosError(e) && e.response?.status === 402) {
            status.value = 'error';
            limitExceeded.value = true;

            return;
        }

        fail(isAxiosError(e) && e.response?.status === 422 ? t('integrations.callback.errors.invalidState') : t('integrations.callback.errors.generic'));
    }
});

function backToChannels(): void {
    router.push({ name: 'channels' });
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex max-w-md flex-col items-start gap-4">
            <h1 class="text-lg font-semibold text-text">{{ t('integrations.callback.title') }}</h1>

            <p v-if="status === 'pending'" class="text-sm text-muted">{{ t('integrations.callback.inProgress') }}</p>

            <p v-else-if="status === 'success'" role="status" class="text-sm text-success">
                {{ t('integrations.callback.success', { name: connectedName }) }}
            </p>

            <p v-else-if="!limitExceeded" role="alert" class="text-sm text-danger">{{ errorMessage }}</p>

            <Button v-if="status !== 'pending'" variant="secondary" @click="backToChannels">
                {{ t('integrations.callback.backToChannels') }}
            </Button>
        </div>
    </AppLayout>
</template>
