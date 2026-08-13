<script setup lang="ts">
import { useI18n } from 'vue-i18n';

export type CalendarView = 'month' | 'week' | 'day';

defineProps<{ modelValue: CalendarView }>();
const emit = defineEmits<{ 'update:modelValue': [CalendarView] }>();

const { t } = useI18n();

const views: CalendarView[] = ['month', 'week', 'day'];
</script>

<template>
    <div class="inline-flex rounded-xl border border-border bg-surface p-0.5" role="group" :aria-label="t('calendar.title')">
        <button
            v-for="view in views"
            :key="view"
            type="button"
            class="rounded-xl px-3 py-1 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :class="modelValue === view ? 'bg-primary-soft font-semibold text-primary' : 'font-normal text-muted hover:text-text'"
            :aria-pressed="modelValue === view"
            @click="emit('update:modelValue', view)"
        >
            {{ t(`calendar.views.${view}`) }}
        </button>
    </div>
</template>
