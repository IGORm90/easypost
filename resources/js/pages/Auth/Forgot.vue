<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AuthLayout from '../../layouts/AuthLayout.vue';
import Input from '../../components/ui/Input.vue';
import Button from '../../components/ui/Button.vue';
import { api } from '../../api/client';

const { t } = useI18n();

const email = ref('');
const loading = ref(false);
// There is deliberately no error state distinguishing "unknown email" from
// "known email" here: the backend (PasswordResetService::sendResetLink())
// always returns 200 with the same message regardless, precisely so this
// page can't become a user-enumeration oracle either.
const done = ref(false);

async function submit(): Promise<void> {
    loading.value = true;

    try {
        await api.post('/auth/password/forgot', { email: email.value });
    } finally {
        loading.value = false;
        done.value = true;
    }
}
</script>

<template>
    <AuthLayout>
        <h1 class="mb-4 text-center text-lg font-semibold text-text">{{ t('auth.forgot.title') }}</h1>

        <p v-if="done" role="status" class="text-sm text-text">{{ t('auth.forgot.success') }}</p>

        <form v-else class="flex flex-col gap-4" @submit.prevent="submit">
            <Input id="forgot-email" v-model="email" type="email" autocomplete="email" :label="t('auth.forgot.email')" required />
            <Button type="submit" variant="primary" :loading="loading">{{ t('auth.forgot.submit') }}</Button>
        </form>

        <p class="mt-4 text-center text-sm">
            <router-link :to="{ name: 'login' }" class="text-primary hover:underline">
                {{ t('auth.forgot.backToLogin') }}
            </router-link>
        </p>
    </AuthLayout>
</template>
