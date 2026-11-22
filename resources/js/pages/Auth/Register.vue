<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import AuthLayout from '../../layouts/AuthLayout.vue';
import Input from '../../components/ui/Input.vue';
import Button from '../../components/ui/Button.vue';
import { api } from '../../api/client';
import { useRegistration } from '../../composables/useRegistration';

const { t } = useI18n();
const router = useRouter();
const registration = useRegistration();

onMounted(registration.load);

const form = reactive({ name: '', email: '', password: '' });
const loading = ref(false);
const errors = ref<Record<string, string[]>>({});
const done = ref(false);

async function submit(): Promise<void> {
    errors.value = {};
    loading.value = true;

    try {
        await api.post('/auth/register', form);
        done.value = true;
    } catch (e) {
        if (isAxiosError(e) && e.response?.status === 422) {
            errors.value = e.response.data?.errors ?? {};
        } else if (isAxiosError(e) && e.response?.status === 403) {
            // The status request said "open" (or failed) but the server has
            // closed registration: show the same notice instead of a form error.
            registration.markClosed();
        } else {
            errors.value = { name: [t('validation.generic')] };
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
        <h1 class="mb-6 text-2xl font-semibold tracking-tight text-text">{{ t('auth.register.title') }}</h1>

        <div v-if="!registration.open.value" class="flex flex-col gap-4">
            <p class="text-sm text-muted" role="status" data-test="registration-closed">{{ t('auth.register.closed') }}</p>
            <Button variant="primary" @click="goToLogin">{{ t('auth.register.loginLink') }}</Button>
        </div>

        <div v-else-if="done" class="flex flex-col gap-4">
            <p class="text-sm text-success" role="status">{{ t('auth.register.success') }}</p>
            <Button variant="primary" @click="goToLogin">{{ t('auth.register.loginLink') }}</Button>
        </div>

        <form v-else class="flex flex-col gap-3.5" @submit.prevent="submit">
            <Input
                id="register-name"
                v-model="form.name"
                :label="t('auth.register.name')"
                :error="errors.name?.[0]"
                required
            />
            <Input
                id="register-email"
                v-model="form.email"
                type="email"
                autocomplete="email"
                :label="t('auth.register.email')"
                :error="errors.email?.[0]"
                required
            />
            <Input
                id="register-password"
                v-model="form.password"
                type="password"
                autocomplete="new-password"
                :label="t('auth.register.password')"
                :error="errors.password?.[0]"
                required
            />

            <Button type="submit" variant="primary" class="mt-1 w-full py-3 text-[15px]" :loading="loading">{{ t('auth.register.submit') }}</Button>
        </form>

    </AuthLayout>
</template>
