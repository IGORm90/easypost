<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import AppLayout from '../../layouts/AppLayout.vue';
import Button from '../../components/ui/Button.vue';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/auth';
import { useOrganizationStore } from '../../stores/organization';

// Landing page for the link InviteController::acceptUrl() mints
// (/invites/{token}) — see that method's docblock. Accepting is a
// deliberate click, not an automatic on-mount call: this is a POST with a
// side effect (grants membership), and the invitee should see what
// they're accepting before it happens.
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const organization = useOrganizationStore();

const token = String(route.params.token ?? '');
const loading = ref(false);
const done = ref(false);
const error = ref<string | null>(null);

async function accept(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        await api.post(`/invites/${token}/accept`);
        // The membership only exists server-side at this point. The auth
        // store's `organizations` is populated once per app load (login()
        // or the router guard's fetchMe()), so without this refresh the
        // organization the user just joined is absent from the switcher —
        // and, if it is their first, no `organization_id` is set at all and
        // every following request 403s — until a manual page reload.
        await auth.fetchMe();
        organization.syncFromAuth();
        done.value = true;
    } catch {
        error.value = t('invites.accept.error');
    } finally {
        loading.value = false;
    }
}

function goHome(): void {
    router.push({ name: 'home' });
}
</script>

<template>
    <AppLayout>
        <!-- «Кобальт»: one centred card with the workspace mark, the
             question and a full-width answer. -->
        <div class="mx-auto mt-10 flex max-w-md flex-col items-center gap-3.5 rounded-xl border border-border bg-surface p-8 text-center">
            <span class="flex h-14 w-14 items-center justify-center rounded-xl bg-primary-soft text-primary-hover" aria-hidden="true">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0113 0M19 8v6M16 11h6" /></svg>
            </span>
            <h1 class="text-[22px] font-semibold tracking-tight text-text">{{ t('invites.accept.title') }}</h1>
            <p class="text-sm leading-relaxed text-muted">{{ t('invites.accept.description') }}</p>
            <p v-if="done" role="status" class="w-full rounded-xl border border-success-border bg-success-soft px-3 py-2 text-[13px] text-success">{{ t('invites.accept.success') }}</p>
            <p v-else-if="error" role="alert" class="w-full rounded-xl border border-danger-border bg-danger-soft px-3 py-2 text-[13px] text-danger">{{ error }}</p>
            <Button v-if="done" variant="primary" class="w-full" @click="goHome">{{ t('calendar.title') }}</Button>
            <Button v-else variant="primary" class="w-full py-3" :loading="loading" @click="accept">{{ t('invites.accept.submit') }}</Button>
        </div>
    </AppLayout>
</template>
