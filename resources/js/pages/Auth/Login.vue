<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import AuthLayout from '../../layouts/AuthLayout.vue';
import Input from '../../components/ui/Input.vue';
import Button from '../../components/ui/Button.vue';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();
const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

const form = reactive({ email: '', password: '' });
const loading = ref(false);
const googleLoading = ref(false);
const oauthError = ref(route.query.oauth_error === '1');
const error = ref<string | null>(oauthError.value ? t('auth.login.oauthError') : null);

async function submit(): Promise<void> {
    oauthError.value = false;
    error.value = null;
    loading.value = true;

    try {
        await auth.login(form.email, form.password);
        await router.push({ name: 'home' });
    } catch (e) {
        // The backend deliberately returns the same generic message for an
        // unknown email and a wrong password (see AuthService) — this just
        // surfaces whatever it sent rather than inventing a more specific
        // one that would defeat that anti-enumeration design.
        if (isAxiosError(e) && e.response?.status === 422) {
            error.value = e.response.data?.errors?.email?.[0] ?? t('auth.login.error');
        } else if (isAxiosError(e) && e.response?.status === 429) {
            error.value = e.response.data?.errors?.email?.[0] ?? t('auth.login.error');
        } else {
            error.value = t('auth.login.error');
        }
    } finally {
        loading.value = false;
    }
}

/**
 * The backend must create Socialite's session-bound state before the
 * browser leaves this origin. It returns the provider URL as JSON instead
 * of redirecting the XHR itself, because only a top-level navigation carries
 * the session cookie back through Google's callback under SameSite=Lax.
 */
async function continueWithGoogle(): Promise<void> {
    oauthError.value = false;
    error.value = null;
    googleLoading.value = true;

    try {
        const { data } = await api.get<{ url: string }>('/auth/oauth/google/redirect');
        window.location.assign(data.url);
    } catch {
        // The callback controller deliberately collapses provider errors
        // into the same generic message; keep an initiation failure equally
        // opaque so browser/network details never become user-facing copy.
        error.value = t('auth.login.oauthError');
    } finally {
        googleLoading.value = false;
    }
}
</script>

<template>
    <AuthLayout>
        <h1 class="mb-4 text-center text-lg font-semibold text-text">{{ t('auth.login.title') }}</h1>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <Input
                id="login-email"
                v-model="form.email"
                type="email"
                autocomplete="email"
                :label="t('auth.login.email')"
                required
            />
            <Input
                id="login-password"
                v-model="form.password"
                type="password"
                autocomplete="current-password"
                :label="t('auth.login.password')"
                required
            />

            <!-- Final review, Critical 3: an expired session bounces the
                 user here from wherever they were. color.md reserves
                 `--danger` for "this failed and nothing was saved" — an
                 expiry is a neutral fact about the session, so it renders
                 as a muted notice, never alongside the login error below
                 as a second red line. -->
            <p v-if="auth.sessionExpired && !error" role="status" class="rounded-md bg-surface-2 px-3 py-2 text-sm text-muted">
                {{ t('auth.login.sessionExpired') }}
            </p>

            <p v-if="error" :data-test="oauthError ? 'oauth-error' : 'login-error'" role="alert" class="text-sm text-danger">{{ error }}</p>

            <Button type="submit" variant="primary" :loading="loading">{{ t('auth.login.submit') }}</Button>
        </form>

        <Button data-test="google-login" type="button" variant="secondary" :loading="googleLoading" @click="continueWithGoogle">
            {{ t('auth.login.google') }}
        </Button>

        <div class="mt-4 flex flex-col items-center gap-2 text-sm">
            <router-link :to="{ name: 'forgot-password' }" class="text-primary hover:underline">
                {{ t('auth.login.forgotLink') }}
            </router-link>
            <p class="text-muted">
                {{ t('auth.login.noAccount') }}
                <router-link :to="{ name: 'register' }" class="text-primary hover:underline">
                    {{ t('auth.login.registerLink') }}
                </router-link>
            </p>
        </div>
    </AuthLayout>
</template>
