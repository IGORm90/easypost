<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import AuthLayout from '../../layouts/AuthLayout.vue';
import Input from '../../components/ui/Input.vue';
import Button from '../../components/ui/Button.vue';
import { api } from '../../api/client';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

// ResetPasswordNotification links here as
// /reset-password?token=...&email=... — see that class's docblock.
const email = String(route.query.email ?? '');
const token = String(route.query.token ?? '');

const form = reactive({ password: '' });
const loading = ref(false);
const error = ref<string | null>(null);
const done = ref(false);

async function submit(): Promise<void> {
    error.value = null;
    loading.value = true;

    try {
        await api.post('/auth/password/reset', { email, token, password: form.password });
        done.value = true;
    } catch (e) {
        if (isAxiosError(e) && e.response?.status === 422) {
            error.value = e.response.data?.errors?.email?.[0] ?? e.response.data?.errors?.password?.[0] ?? t('auth.reset.invalidLink');
        } else {
            error.value = t('auth.reset.invalidLink');
        }
    } finally {
        loading.value = false;
    }
}

function goToLogin(): void {
    router.push({ name: 'login' });
}
</script>

<template>
    <AuthLayout>
        <h1 class="mb-4 text-center text-lg font-semibold text-text">{{ t('auth.reset.title') }}</h1>

        <div v-if="done" class="flex flex-col gap-4">
            <p role="status" class="text-sm text-success">{{ t('auth.reset.success') }}</p>
            <Button variant="primary" @click="goToLogin">{{ t('auth.reset.loginLink') }}</Button>
        </div>

        <form v-else class="flex flex-col gap-4" @submit.prevent="submit">
            <Input
                id="reset-password"
                v-model="form.password"
                type="password"
                autocomplete="new-password"
                :label="t('auth.reset.password')"
                required
            />
            <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
            <Button type="submit" variant="primary" :loading="loading">{{ t('auth.reset.submit') }}</Button>
        </form>
    </AuthLayout>
</template>
