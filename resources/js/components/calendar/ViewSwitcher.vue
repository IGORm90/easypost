<script setup lang="ts">
import { useI18n } from 'vue-i18n';

export type CalendarView = 'month' | 'week' | 'day';

defineProps<{ modelValue: CalendarView }>();
const emit = defineEmits<{ 'update:modelValue': [CalendarView] }>();

const { t } = useI18n();

const views: CalendarView[] = ['month', 'week', 'day'];
</script>

<template>
    <!-- color.md §3.1 (final review, Important 9): the active segment is
     surface + weight, not primary. A segmented control reports state; it
     is not the screen's action, and the calendar already spends its two
     primaries on the active nav link and "New post". `aria-pressed` plus
     the weight change keep it distinguishable without colour alone (§4). -->
    <div class="inline-flex rounded-xl border border-border-strong bg-surface p-0.5" role="group" :aria-label="t('calendar.title')">
        <button
            v-for="view in views"
            :key="view"
            type="button"
            class="rounded-xl px-3 py-1 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :class="modelValue === view ? 'bg-surface-2 font-semibold text-text' : 'font-normal text-muted hover:text-text'"
            :aria-pressed="modelValue === view"
            @click="emit('update:modelValue', view)"
        >
            {{ t(`calendar.views.${view}`) }}
        </button>
    </div>
</template>
