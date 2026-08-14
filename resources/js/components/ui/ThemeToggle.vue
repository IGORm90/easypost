<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useTheme } from '../../composables/useTheme';

const { theme, toggle } = useTheme();
const { t } = useI18n();
</script>

<template>
    <button
        type="button"
        class="inline-flex items-center justify-center rounded-xl border border-border bg-surface p-2 text-text hover:bg-surface-2 focus:outline-none focus:ring-2 focus:ring-primary"
        :aria-label="t('nav.toggleTheme')"
        :aria-pressed="theme === 'dark'"
        @click="toggle"
    >
        <svg v-if="theme === 'dark'" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path
                d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"
            />
        </svg>
        <svg v-else class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path
                d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 9a1 1 0 100 2h1a1 1 0 100-2h-1zM4.464 4.95a1 1 0 000 1.414l.707.707A1 1 0 106.585 5.65l-.707-.707a1 1 0 00-1.414 0zM3 9a1 1 0 100 2H2a1 1 0 100-2h1zm2.878 8.657a1 1 0 001.414 0l.707-.707a1 1 0 10-1.414-1.414l-.707.707a1 1 0 000 1.414zM10 15a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1z"
            />
        </svg>
    </button>
</template>
