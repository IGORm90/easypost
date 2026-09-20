<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppLayout from '../../layouts/AppLayout.vue';
import { useAuthStore } from '../../stores/auth';
import { useOrganizationStore } from '../../stores/organization';

const { t } = useI18n();
const auth = useAuthStore();
const organization = useOrganizationStore();
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex min-w-0 max-w-3xl flex-col gap-6">
            <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-1">
                    <h1 class="text-xl font-semibold text-text">{{ t('organization.list.title') }}</h1>
                    <p class="text-sm text-muted">{{ t('organization.list.description') }}</p>
                </div>
                <router-link
                    :to="{ name: 'workspace-create' }"
                    class="inline-flex items-center justify-center rounded-xl bg-primary px-4 py-2 text-sm font-medium text-on-primary transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                    {{ t('organization.create.entry') }}
                </router-link>
            </div>

            <ul v-if="auth.organizations.length" class="flex flex-col gap-3">
                <li
                    v-for="workspace in auth.organizations"
                    :key="workspace.id"
                    :aria-current="workspace.id === organization.current?.id ? 'true' : undefined"
                    class="flex min-w-0 items-start justify-between gap-3 rounded-xl border border-border bg-surface p-4"
                >
                    <span class="min-w-0 text-sm font-medium text-text [overflow-wrap:anywhere]">{{ workspace.name }}</span>
                    <span
                        v-if="workspace.id === organization.current?.id"
                        class="shrink-0 rounded-lg bg-surface-2 px-2 py-1 text-xs font-medium text-muted"
                    >
                        {{ t('organization.list.current') }}
                    </span>
                </li>
            </ul>
            <p v-else class="rounded-xl border border-border bg-surface p-6 text-sm text-muted">
                {{ t('organization.list.empty') }}
            </p>
        </div>
    </AppLayout>
</template>
