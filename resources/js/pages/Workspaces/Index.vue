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
        <div class="flex min-w-0 max-w-5xl flex-col gap-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <h1 class="text-[26px] font-semibold leading-tight tracking-tight text-text">{{ t('organization.list.title') }}</h1>
                    <p class="text-sm text-muted">{{ t('organization.list.description') }}</p>
                </div>
                <router-link
                    :to="{ name: 'workspace-create' }"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-on-primary transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    {{ t('organization.create.entry') }}
                </router-link>
            </div>

            <!-- «Кобальт»: a grid of workspace cards — a letter avatar, the
                 name, and either the "Current" badge or a switch button. -->
            <ul v-if="auth.organizations.length" class="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">
                <li
                    v-for="workspace in auth.organizations"
                    :key="workspace.id"
                    :aria-current="workspace.id === organization.current?.id ? 'true' : undefined"
                    class="flex min-w-0 flex-col gap-3 rounded-lg border bg-surface p-4"
                    :class="workspace.id === organization.current?.id ? 'border-primary' : 'border-border'"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-base font-semibold text-accent" aria-hidden="true">
                            {{ workspace.name.trim().charAt(0).toUpperCase() }}
                        </span>
                        <span class="min-w-0 flex-1 text-sm font-semibold text-text [overflow-wrap:anywhere]">{{ workspace.name }}</span>
                        <span
                            v-if="workspace.id === organization.current?.id"
                            class="shrink-0 rounded bg-primary-soft px-2 py-0.5 text-[11px] font-semibold text-primary-hover"
                        >
                            {{ t('organization.list.current') }}
                        </span>
                    </div>
                    <button
                        v-if="workspace.id !== organization.current?.id"
                        type="button"
                        data-test="workspace-switch"
                        class="self-start rounded-xl border border-border-strong bg-surface px-3 py-1.5 text-[13px] font-medium text-text hover:bg-surface-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        @click="organization.switchTo(workspace.id)"
                    >
                        {{ t('organization.list.switch') }}
                    </button>
                </li>
            </ul>
            <p v-else class="rounded-lg border border-border bg-surface p-6 text-sm text-muted">
                {{ t('organization.list.empty') }}
            </p>
        </div>
    </AppLayout>
</template>
