<script setup lang="ts">
// A single toast notification. Status is conveyed through the icon and the
// message text — never colour alone (color.md §3.3 / §4).
type Variant = 'success' | 'warning' | 'danger' | 'neutral' | 'muted';

withDefaults(defineProps<{ variant?: Variant }>(), { variant: 'neutral' });

const emit = defineEmits<{ dismiss: [] }>();

const variantClasses: Record<Variant, string> = {
    success: 'border-success/40 bg-success-soft text-success',
    warning: 'border-warning/40 bg-warning-soft text-warning',
    danger: 'border-danger/40 bg-danger-soft text-danger',
    neutral: 'border-border bg-surface text-text',
    // color.md §3.8 (final review, Important 9): a notice that is neither
    // a failure nor a limit — "this is locked", "confirm this" — must not
    // borrow --warning, which on the editor screen already means "plan
    // limit reached" and sits beside PreviewPane's accent-tinted avatars.
    // Recessed rather than coloured, on the sidebar surface so it still
    // reads as a distinct block against the modal's own --surface.
    muted: 'border-border bg-surface-2 text-muted',
};
</script>

<template>
    <div
        role="status"
        class="flex items-start gap-2 rounded-xl border p-3 text-sm shadow-sm"
        :class="variantClasses[variant]"
    >
        <div class="flex-1">
            <slot />
        </div>
        <button
            type="button"
            class="text-muted hover:text-text focus:outline-none focus:ring-2 focus:ring-primary"
            @click="emit('dismiss')"
        >
            &times;
        </button>
    </div>
</template>
