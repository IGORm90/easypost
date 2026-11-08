<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import AppLayout from '../../layouts/AppLayout.vue';
import Card from '../../components/ui/Card.vue';
import Input from '../../components/ui/Input.vue';
import Button from '../../components/ui/Button.vue';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/auth';

const { t, availableLocales, locale } = useI18n();
const auth = useAuthStore();

const form = reactive({ name: '', timezone: 'UTC', locale: 'en', email_notifications: true });
const errors = ref<Record<string, string[]>>({});
const saving = ref(false);
const saved = ref(false);

onMounted(() => {
    if (auth.user) {
        form.name = auth.user.name;
        form.timezone = auth.user.timezone;
        form.locale = auth.user.locale;
        form.email_notifications = auth.user.email_notifications ?? true;
    }
});

// Falls back to a short curated list when the browser doesn't support
// Intl.supportedValuesOf (older Safari) — either way the user's current
// value is always present so the <select> never silently drops it.
const FALLBACK_ZONES = ['UTC', 'Europe/Moscow', 'Europe/London', 'America/New_York', 'America/Los_Angeles', 'Asia/Tokyo', 'Asia/Almaty'];

const timezones = computed<string[]>(() => {
    const supportedValuesOf = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf;
    const zones = supportedValuesOf ? supportedValuesOf('timeZone') : FALLBACK_ZONES;

    return zones.includes(form.timezone) ? zones : [form.timezone, ...zones];
});

async function submit(): Promise<void> {
    errors.value = {};
    saving.value = false;
    saved.value = false;
    saving.value = true;

    try {
        const { data } = await api.put('/profile', { ...form });
        auth.user = data.user;
        locale.value = data.user.locale;
        saved.value = true;
    } catch (e) {
        if (isAxiosError(e) && e.response?.status === 422) {
            errors.value = e.response.data?.errors ?? {};
        }
    } finally {
        saving.value = false;
    }
}

const rotating = ref(false);
const newApiKey = ref<string | null>(null);
const copied = ref(false);

async function rotateApiKey(): Promise<void> {
    if (!window.confirm(t('settings.profile.apiKey.rotateConfirm'))) {
        return;
    }

    rotating.value = true;
    copied.value = false;

    try {
        const { data } = await api.post<{ api_key: string }>('/profile/api-key/rotate');
        newApiKey.value = data.api_key;
    } finally {
        rotating.value = false;
    }
}

async function copyApiKey(): Promise<void> {
    if (!newApiKey.value) {
        return;
    }

    await navigator.clipboard.writeText(newApiKey.value);
    copied.value = true;
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex max-w-xl flex-col gap-6">
            <Card :title="t('settings.profile.title')">
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <Input
                        id="profile-name"
                        v-model="form.name"
                        :label="t('settings.profile.name')"
                        :error="errors.name?.[0]"
                        required
                    />

                    <div class="flex flex-col gap-1">
                        <label for="profile-timezone" class="text-sm font-medium text-text">
                            {{ t('settings.profile.timezone') }}
                        </label>
                        <select
                            id="profile-timezone"
                            v-model="form.timezone"
                            class="rounded-xl border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                        >
                            <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
                        </select>
                        <p v-if="errors.timezone?.[0]" class="text-sm text-danger">{{ errors.timezone[0] }}</p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="profile-locale" class="text-sm font-medium text-text">
                            {{ t('settings.profile.locale') }}
                        </label>
                        <select
                            id="profile-locale"
                            v-model="form.locale"
                            class="rounded-xl border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                        >
                            <option v-for="l in availableLocales" :key="l" :value="l">{{ l }}</option>
                        </select>
                        <p v-if="errors.locale?.[0]" class="text-sm text-danger">{{ errors.locale[0] }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-text">
                        <input id="profile-notifications" v-model="form.email_notifications" type="checkbox" class="h-4 w-4 accent-primary" />
                        {{ t('settings.profile.emailNotifications') }}
                    </label>

                    <p v-if="saved" role="status" class="text-sm text-success">{{ t('settings.profile.saved') }}</p>

                    <Button type="submit" variant="primary" :loading="saving">{{ t('settings.profile.save') }}</Button>
                </form>
            </Card>

            <Card :title="t('settings.profile.apiKey.title')">
                <p class="mb-4 text-sm text-muted">{{ t('settings.profile.apiKey.description') }}</p>

                <div v-if="newApiKey" class="mb-4 flex flex-col gap-2 rounded-xl border border-border bg-surface-2 p-3">
                    <p class="text-sm text-success">{{ t('settings.profile.apiKey.generated') }}</p>
                    <code class="break-all rounded-xl bg-surface p-2 text-xs text-text">{{ newApiKey }}</code>
                    <Button variant="secondary" @click="copyApiKey">
                        {{ copied ? t('ui.copied') : t('ui.copy') }}
                    </Button>
                </div>

                <Button variant="secondary" :loading="rotating" @click="rotateApiKey">
                    {{ t('settings.profile.apiKey.rotate') }}
                </Button>
            </Card>
        </div>
    </AppLayout>
</template>
