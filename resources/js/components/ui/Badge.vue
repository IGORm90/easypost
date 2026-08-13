<script setup lang="ts">
// Status colours per color.md §1 "Статусы" and §3.3 — always shown with
// text, never colour alone, and never reused for decoration.
type Status = 'success' | 'warning' | 'danger' | 'neutral' | 'scheduled';

withDefaults(defineProps<{ status?: Status }>(), { status: 'neutral' });

const statusClasses: Record<Status, string> = {
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
    neutral: 'bg-surface-2 text-muted',
    // color.md §1: `--primary-soft` is explicitly "Фон бейджа «Запланирован»"
    // — the one badge status that borrows the primary scale rather than
    // the success/warning/danger set, for the calendar's QUEUE state.
    scheduled: 'bg-primary-soft text-primary',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-xl px-2 py-0.5 text-xs font-medium"
        :class="statusClasses[status]"
    >
        <slot />
    </span>
</template>
