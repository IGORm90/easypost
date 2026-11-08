<script setup lang="ts">
// Status colours per color.md §1 "Статусы" and §3.3 — always shown with
// text, never colour alone, and never reused for decoration.
type Status = 'success' | 'warning' | 'danger' | 'neutral' | 'scheduled';

withDefaults(defineProps<{ status?: Status }>(), { status: 'neutral' });

const statusClasses: Record<Status, string> = {
    success: 'border-success-border bg-success-soft text-success',
    warning: 'border-warning-border bg-warning-soft text-warning',
    danger: 'border-danger-border bg-danger-soft text-danger',
    neutral: 'border-accent-soft bg-accent-soft text-accent',
    // color.md §1: `--primary-soft` is explicitly "Фон бейджа «Запланирован»"
    // — the one badge status that borrows the primary scale rather than
    // the success/warning/danger set, for the calendar's QUEUE state.
    scheduled: 'border-primary-soft bg-primary-soft text-primary-hover',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1 whitespace-nowrap rounded border px-2 py-0.5 text-xs font-semibold"
        :class="statusClasses[status]"
    >
        <slot />
    </span>
</template>
