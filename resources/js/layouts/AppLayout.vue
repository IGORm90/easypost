<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import ThemeToggle from '../components/ui/ThemeToggle.vue';

const { t } = useI18n();
</script>

<template>
    <div class="flex min-h-screen bg-bg text-text">
        <!-- Sidebar sits on the third background level (color.md §3.4). -->
        <aside class="hidden w-56 shrink-0 border-r border-border bg-surface-2 p-4 md:block">
            <p class="text-sm font-semibold">{{ t('app.name') }}</p>
        </aside>

        <div class="flex flex-1 flex-col">
            <header class="flex items-center justify-between border-b border-border bg-surface px-4 py-3">
                <span class="text-sm text-muted">{{ t('nav.menu') }}</span>
                <ThemeToggle />
            </header>

            <main class="flex-1 p-4">
                <slot>
                    <p class="text-sm text-muted">{{ t('shell.comingSoon') }}</p>
                </slot>
            </main>
        </div>
    </div>
</template>
