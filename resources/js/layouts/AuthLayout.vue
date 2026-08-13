<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import ThemeToggle from '../components/ui/ThemeToggle.vue';

const { t } = useI18n();
</script>

<template>
    <div class="relative flex min-h-screen flex-col items-center justify-center gap-6 bg-bg p-4 text-text">
        <div class="absolute right-4 top-4">
            <ThemeToggle />
        </div>

        <div class="w-full max-w-sm rounded-xl border border-border bg-surface p-6 shadow-sm">
            <p class="mb-4 text-center text-base font-semibold">{{ t('app.name') }}</p>
            <slot>
                <p class="text-center text-sm text-muted">{{ t('shell.comingSoon') }}</p>
            </slot>
        </div>
    </div>
</template>
