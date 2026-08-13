<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import ThemeToggle from '../components/ui/ThemeToggle.vue';
import OrganizationSwitcher from '../components/OrganizationSwitcher.vue';
import { useAuthStore } from '../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();
</script>

<template>
    <div class="flex min-h-screen bg-bg text-text">
        <!-- Sidebar sits on the third background level (color.md §3.4). -->
        <aside class="hidden w-56 shrink-0 border-r border-border bg-surface-2 p-4 md:flex md:flex-col md:gap-1">
            <p class="mb-3 text-sm font-semibold">{{ t('app.name') }}</p>
            <router-link
                :to="{ name: 'home' }"
                class="rounded-xl px-2 py-1.5 text-sm text-muted hover:bg-surface hover:text-text"
                active-class="bg-primary-soft text-primary"
            >
                {{ t('calendar.title') }}
            </router-link>
            <router-link
                :to="{ name: 'channels' }"
                class="rounded-xl px-2 py-1.5 text-sm text-muted hover:bg-surface hover:text-text"
                active-class="bg-primary-soft text-primary"
            >
                {{ t('nav.channels') }}
            </router-link>
            <router-link
                :to="{ name: 'settings-profile' }"
                class="rounded-xl px-2 py-1.5 text-sm text-muted hover:bg-surface hover:text-text"
                active-class="bg-primary-soft text-primary"
            >
                {{ t('nav.settingsProfile') }}
            </router-link>
            <router-link
                :to="{ name: 'settings-team' }"
                class="rounded-xl px-2 py-1.5 text-sm text-muted hover:bg-surface hover:text-text"
                active-class="bg-primary-soft text-primary"
            >
                {{ t('nav.settingsTeam') }}
            </router-link>
        </aside>

        <div class="flex flex-1 flex-col">
            <header class="flex items-center justify-between gap-4 border-b border-border bg-surface px-4 py-3">
                <OrganizationSwitcher v-if="auth.user" />
                <div class="ml-auto flex items-center gap-3">
                    <ThemeToggle />
                    <button
                        v-if="auth.user"
                        type="button"
                        class="text-sm text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        @click="auth.logout()"
                    >
                        {{ t('nav.logout') }}
                    </button>
                </div>
            </header>

            <main class="flex-1 p-4">
                <slot>
                    <p class="text-sm text-muted">{{ t('shell.comingSoon') }}</p>
                </slot>
            </main>
        </div>
    </div>
</template>
