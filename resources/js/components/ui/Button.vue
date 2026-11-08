<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

// `disabled` is declared explicitly (rather than left to fall through as
// a bare HTML attribute) so it can be combined with `loading` instead of
// racing it: before this prop existed, a parent passing `:disabled="x"`
// landed via Vue's attrs fallthrough and *won* over this component's own
// `:disabled="loading"` binding, meaning a caller like
// ScheduleControls.vue passing both `:loading="saving"` and
// `:disabled="someUnrelatedFalseCondition"` re-enabled the button mid
// in-flight request. See Task 25 review, Minor 1.
const props = withDefaults(
    defineProps<{
        variant?: Variant;
        loading?: boolean;
        disabled?: boolean;
        type?: 'button' | 'submit' | 'reset';
    }>(),
    {
        variant: 'primary',
        loading: false,
        disabled: false,
        type: 'button',
    },
);

const emit = defineEmits<{ click: [MouseEvent] }>();

const { t } = useI18n();

const variantClasses: Record<Variant, string> = {
    primary: 'bg-primary font-semibold text-on-primary hover:bg-primary-hover focus-visible:ring-primary',
    secondary: 'font-medium border border-border-strong bg-surface text-text hover:bg-surface-2 focus-visible:ring-border-strong',
    ghost: 'font-medium text-muted hover:bg-surface-2 hover:text-text focus-visible:ring-border-strong',
    danger: 'bg-danger font-medium text-on-danger hover:opacity-90 focus-visible:ring-danger',
};

const classes = computed(() => variantClasses[props.variant]);
const isDisabled = computed(() => props.loading || props.disabled);

function handleClick(event: MouseEvent): void {
    if (isDisabled.value) {
        return;
    }

    emit('click', event);
}
</script>

<template>
    <button
        :type="type"
        :aria-busy="loading"
        :disabled="isDisabled"
        :class="[
            'inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm transition',
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
