<script setup lang="ts">
import { useI18n } from 'vue-i18n';

export type DisplayMode = 'grid' | 'list';

defineProps<{ modelValue: DisplayMode }>();
const emit = defineEmits<{ 'update:modelValue': [DisplayMode] }>();

const { t } = useI18n();

const modes: DisplayMode[] = ['grid', 'list'];
</script>

<template>
    <!-- Separate from ViewSwitcher on purpose: month/week/day is a zoom
     level of the *grid*, this is which surface renders at all. Styling is
     ViewSwitcher's, and for the same reason (color.md §3.1): a segmented
     control reports state and must not spend the screen's primary budget,
     which the active nav link and "New post" have already used up.
     `aria-pressed` plus the weight change carry it without colour (§4). -->
    <div class="inline-flex rounded-xl border border-border bg-surface p-0.5" role="group" :aria-label="t('calendar.mode.label')">
        <button
            v-for="mode in modes"
            :key="mode"
            type="button"
            class="rounded-xl px-3 py-1 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :class="modelValue === mode ? 'bg-surface-2 font-semibold text-text' : 'font-normal text-muted hover:text-text'"
            :aria-pressed="modelValue === mode"
            @click="emit('update:modelValue', mode)"
        >
            {{ t(`calendar.mode.${mode}`) }}
        </button>
    </div>
</template>
