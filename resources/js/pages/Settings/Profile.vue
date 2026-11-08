<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import AppLayout from '../../layouts/AppLayout.vue';
import Input from '../../components/ui/Input.vue';
import Button from '../../components/ui/Button.vue';
import PlanUsagePanel from '../../components/billing/PlanUsagePanel.vue';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/auth';

const { t, availableLocales, locale } = useI18n();
const auth = useAuthStore();

const form = reactive({ name: '', timezone: 'UTC', locale: 'en', email_notifications: true });
const errors = ref<Record<string, string[]>>({});
const saving = ref(false);
const saved = ref(false);

/** Initials for the avatar above the form. */
const initials = computed(() =>
    (auth.user?.name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join(''),
);

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
        <!-- «Кобальт»: personal data on the left; the plan's usage and the
             API key on the right. They stack below `lg`. -->
        <div class="flex flex-col gap-5">
            <h1 class="text-[26px] font-semibold leading-tight tracking-tight text-text">{{ t('settings.profile.pageTitle') }}</h1>

            <div class="flex flex-wrap items-start gap-5">
                <section data-test="profile-personal" class="flex min-w-[320px] flex-[3_1_420px] flex-col gap-4 rounded-lg border border-border bg-surface p-6">
                    <div class="flex items-center gap-3.5">
                        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary text-lg font-semibold text-on-primary" aria-hidden="true">{{ initials }}</span>
                        <div class="flex min-w-0 flex-col">
                            <h2 class="text-base font-semibold text-text">{{ t('settings.profile.title') }}</h2>
                            <span class="truncate text-[13px] text-muted">{{ auth.user?.email }}</span>
                        </div>
                    </div>
                    <form class="flex flex-col gap-4" @submit.prevent="submit">
                        <Input
                            id="profile-name"
                            v-model="form.name"
                            :label="t('settings.profile.name')"
                            :error="errors.name?.[0]"
                            required
                        />

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="flex flex-col gap-1">
                                <label for="profile-timezone" class="text-[13px] font-medium text-text">
                                    {{ t('settings.profile.timezone') }}
                                </label>
                                <select
                                    id="profile-timezone"
                                    v-model="form.timezone"
                                    class="rounded-xl border border-border-strong bg-surface px-3 py-2.5 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                                >
                                    <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
                                </select>
                                <p v-if="errors.timezone?.[0]" class="text-xs text-danger">{{ errors.timezone[0] }}</p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="profile-locale" class="text-[13px] font-medium text-text">
                                    {{ t('settings.profile.locale') }}
                                </label>
                                <select
                                    id="profile-locale"
                                    v-model="form.locale"
                                    class="rounded-xl border border-border-strong bg-surface px-3 py-2.5 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                                >
                                    <option v-for="l in availableLocales" :key="l" :value="l">{{ l }}</option>
                                </select>
                                <p v-if="errors.locale?.[0]" class="text-xs text-danger">{{ errors.locale[0] }}</p>
                            </div>
                        </div>

                        <label for="profile-notifications" class="flex items-center justify-between gap-3 rounded-xl border border-border bg-surface-2 px-3.5 py-3">
                            <span class="flex flex-col">
                                <span class="text-sm font-medium text-text">{{ t('settings.profile.emailNotifications') }}</span>
                                <span class="text-xs text-muted">{{ t('settings.profile.emailNotificationsHint') }}</span>
                            </span>
                            <input id="profile-notifications" v-model="form.email_notifications" type="checkbox" role="switch" class="h-5 w-5 accent-primary" />
                        </label>

                        <div class="flex items-center gap-3">
                            <Button type="submit" variant="primary" :loading="saving">{{ t('settings.profile.save') }}</Button>
                            <p v-if="saved" role="status" class="text-[13px] text-success">{{ t('settings.profile.saved') }}</p>
                        </div>
                    </form>
                </section>

                <div data-test="profile-side" class="flex min-w-[300px] flex-[2_1_340px] flex-col gap-5">
                    <PlanUsagePanel />

                    <section class="flex flex-col gap-3 rounded-lg border border-border bg-surface p-6">
                        <h2 class="text-base font-semibold text-text">{{ t('settings.profile.apiKey.title') }}</h2>
                        <p class="text-[13px] leading-relaxed text-muted">{{ t('settings.profile.apiKey.description') }}</p>
                        <div v-if="newApiKey" class="flex flex-col gap-2">
                            <p role="status" class="rounded-xl border border-success-border bg-success-soft px-3 py-2 text-[13px] text-success">{{ t('settings.profile.apiKey.generated') }}</p>
                            <div class="flex gap-1.5">
                                <code class="min-w-0 flex-1 break-all rounded-xl bg-nav px-2.5 py-2 font-mono text-xs text-nav-text">{{ newApiKey }}</code>
                                <Button variant="secondary" @click="copyApiKey">
                                    {{ copied ? t('ui.copied') : t('ui.copy') }}
                                </Button>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="self-start rounded-xl border border-danger-border bg-surface px-3 py-2 text-[13px] text-danger hover:bg-danger-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-danger disabled:opacity-60"
                            :disabled="rotating"
                            :aria-busy="rotating"
                            @click="rotateApiKey"
                        >
                            {{ t('settings.profile.apiKey.rotate') }}
                        </button>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
