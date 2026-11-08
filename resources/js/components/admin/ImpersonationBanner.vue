<script setup lang="ts">
import { isAxiosError } from 'axios';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/auth';
import { restartSession } from '../../utils/session';

/**
 * The standing reminder that this session is not yours (Task 1.7).
 *
 * Impersonation swaps the Sanctum session cookie server-side and changes
 * nothing else: every screen renders exactly as it would for the other
 * person, with no trace of who is actually driving. Without a banner it
 * is genuinely easy to keep working — and publishing — as someone else
 * by accident, which is why this is the one part of the admin feature
 * that is not optional.
 *
 * Mounted once in AppLayout.vue alongside LimitToast, because there is no
 * root App.vue in this project (app.ts does `createApp(RouterView)`). The
 * consequence is deliberate and worth stating: pages that use
 * AuthLayout, and the public share page, do not show it. Neither is a
 * place where an impersonated session can do damage.
 *
 * A warning, not a danger (color.md): nothing has gone wrong, but the
 * state needs saying. The icon and the sentence carry the meaning so the
 * tint is never the only signal (§4).
 */
const { t } = useI18n();
const auth = useAuthStore();

const stopping = ref(false);
const error = ref<string | null>(null);

const impersonator = computed(() => auth.impersonator);

/**
 * ImpersonationService::stop() invalidates the session outright when the
 * account that started the impersonation has been deleted since — there
 * is nobody left to hand the session back to. Retrying cannot help and
 * staying put means sitting on a dead session, so that one case
 * restarts straight into the login page rather than showing an error
 * beside a button that can never work.
 */
function isActorGone(e: unknown): boolean {
    return (
        isAxiosError(e)
        && e.response?.status === 403
        && String(e.response?.data?.message ?? '').includes('no longer exists')
    );
}

async function stop(): Promise<void> {
    if (stopping.value) {
        return;
    }

    stopping.value = true;
    error.value = null;

    try {
        await api.post('/admin/impersonate/stop');
        // The cookie now authenticates as the original actor again, and
        // every store still holds the borrowed user's data — see
        // restartSession()'s docblock for why this is a reload.
        restartSession();
    } catch (e) {
        if (isActorGone(e)) {
            restartSession();

            return;
        }

        error.value = t('admin.impersonating.stopError');
    } finally {
        stopping.value = false;
    }
}
</script>

<template>
    <div
        v-if="impersonator"
        data-test="impersonation-banner"
        class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-warning-border bg-warning-soft px-6 py-2.5 text-[13px] text-warning"
        role="status"
    >
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path
                fill-rule="evenodd"
                d="M8.257 3.099c.765-1.36 2.72-1.36 3.486 0l6.516 11.59c.75 1.334-.213 2.98-1.743 2.98H3.483c-1.53 0-2.492-1.646-1.743-2.98l6.517-11.59zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                clip-rule="evenodd"
            />
        </svg>
        <span class="font-medium">{{ t('admin.impersonating.label', { name: auth.user?.name ?? '' }) }}</span>
        <span class="text-muted">{{ t('admin.impersonating.hint') }}</span>
        <button
            data-test="impersonation-stop"
            type="button"
            class="ml-auto rounded-xl border border-warning px-3 py-1.5 font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-warning disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="stopping"
            @click="stop"
        >
            {{ t('admin.impersonating.stop') }}
        </button>
        <p v-if="error" class="w-full text-danger">{{ error }}</p>
    </div>
</template>
