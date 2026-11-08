<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import ThemeToggle from '../components/ui/ThemeToggle.vue';

const { t } = useI18n();

/*
 * The week-by-channel strip in the pitch panel: one row per network,
 * one cell per weekday, a filled cell where a post sits. Pure decoration
 * (aria-hidden) — it hints at the product's main screen without inventing
 * any figures about it.
 */
const LANES = [
    [1, 0, 1, 1, 0, 0, 0],
    [1, 0, 1, 0, 0, 0, 1],
    [0, 1, 0, 0, 1, 0, 0],
    [0, 0, 1, 0, 0, 1, 0],
];
</script>

<template>
    <div class="flex min-h-screen bg-surface text-text">
        <section
            data-test="auth-brand"
            class="hidden w-1/2 max-w-2xl flex-col gap-10 bg-nav px-14 py-12 text-nav-text lg:flex"
        >
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded bg-on-nav text-sm font-bold text-nav" aria-hidden="true">EP</span>
                <span class="text-lg font-semibold text-on-nav">{{ t('app.name') }}</span>
            </div>
            <div class="mt-auto flex max-w-lg flex-col gap-4">
                <h2 class="text-4xl font-semibold leading-tight tracking-tight text-on-nav">{{ t('auth.brand.title') }}</h2>
                <p class="text-base leading-relaxed">{{ t('auth.brand.subtitle') }}</p>
            </div>
            <div class="flex flex-col gap-2 rounded-lg border border-nav-border bg-nav-active p-4" aria-hidden="true">
                <div v-for="(lane, row) in LANES" :key="row" class="grid grid-cols-7 gap-1.5">
                    <span
                        v-for="(filled, col) in lane"
                        :key="col"
                        class="h-4 rounded"
                        :class="filled ? 'bg-nav-muted' : 'bg-nav-border'"
                    />
                </div>
            </div>
        </section>

        <main class="relative flex flex-1 flex-col items-center justify-center px-4 py-12">
            <div class="absolute right-4 top-4">
                <ThemeToggle />
            </div>
            <div class="w-full max-w-sm">
                <p class="mb-8 flex items-center gap-2 text-base font-semibold lg:hidden">
                    <span class="flex h-7 w-7 items-center justify-center rounded bg-nav text-xs font-bold text-on-nav" aria-hidden="true">EP</span>
                    {{ t('app.name') }}
                </p>
                <slot>
                    <p class="text-sm text-muted">{{ t('shell.comingSoon') }}</p>
                </slot>
            </div>
        </main>
    </div>
</template>
