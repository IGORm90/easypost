<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        loading?: boolean;
        type?: 'button' | 'submit' | 'reset';
    }>(),
    {
        variant: 'primary',
        loading: false,
        type: 'button',
    },
);

const emit = defineEmits<{ click: [MouseEvent] }>();

const { t } = useI18n();

const variantClasses: Record<Variant, string> = {
    primary: 'bg-primary text-on-primary hover:bg-primary-hover focus-visible:ring-primary',
    secondary: 'border border-border text-text hover:bg-surface-2 focus-visible:ring-border',
    ghost: 'text-muted hover:text-text focus-visible:ring-border',
    danger: 'bg-danger text-white hover:opacity-90 focus-visible:ring-danger',
};

const classes = computed(() => variantClasses[props.variant]);

function handleClick(event: MouseEvent): void {
    if (props.loading) {
        return;
    }

    emit('click', event);
}
</script>

<template>
    <button
        :type="type"
        :aria-busy="loading"
        :disabled="loading"
        :class="[
            'inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transition',
            'focus-visible:outline-none focus-visible:ring-2',
            'disabled:cursor-not-allowed disabled:opacity-60',
            classes,
        ]"
        @click="handleClick"
    >
        <span v-if="loading" class="sr-only">{{ t('ui.loading') }}</span>
        <slot />
    </button>
</template>
