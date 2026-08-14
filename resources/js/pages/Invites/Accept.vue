<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import AppLayout from '../../layouts/AppLayout.vue';
import Card from '../../components/ui/Card.vue';
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
        <div class="mx-auto max-w-md">
            <Card :title="t('invites.accept.title')">
                <p class="mb-4 text-sm text-muted">{{ t('invites.accept.description') }}</p>

                <p v-if="done" role="status" class="mb-4 text-sm text-success">{{ t('invites.accept.success') }}</p>
                <p v-else-if="error" role="alert" class="mb-4 text-sm text-danger">{{ error }}</p>

                <Button v-if="done" variant="primary" @click="goHome">{{ t('nav.menu') }}</Button>
                <Button v-else variant="primary" :loading="loading" @click="accept">{{ t('invites.accept.submit') }}</Button>
            </Card>
        </div>
    </AppLayout>
</template>
