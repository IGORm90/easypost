<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { utcIsoToZonedInput, zonedInputToUtcIso } from '../../utils/zonedDateTime';
import type { RepeatRule, RepeatUnit } from './types';

/**
 * Block 4: the recurrence rule of a post — "every N days/weeks/months,
 * until this date".
 *
 * Deliberately dumb: it owns no state, performs no requests, and knows
 * nothing about which post it is editing. It receives the rule and the
 * author's timezone, and emits a whole rule back — the same props-in
 * shape `ChannelPicker.vue` documents as this editor's house style.
 *
 * The rule is emitted WHOLE, never field by field: `unit === null` is
 * the one canonical "does not repeat", and leaving an interval or an end
 * date behind when the unit is cleared would let a later edit resurrect
 * a rule the user believes they switched off.
 *
 * The interval ceilings below mirror `RepeatUnit::maxInterval()` on the
 * backend. They are a courtesy, not the enforcement — the server checks
 * the same thing — but a field that can only produce values the server
 * accepts beats a 422 the user has to read.
 */
const props = defineProps<{
    modelValue: RepeatRule;
    /** The signed-in user's profile timezone — the same value ScheduleControls.vue renders `publish_date` in. */
    timezone: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [RepeatRule];
}>();

const { t } = useI18n();

const MAX_INTERVAL: Record<RepeatUnit, number> = {
    DAY: 365,
    WEEK: 52,
    MONTH: 12,
};

const UNITS: RepeatUnit[] = ['DAY', 'WEEK', 'MONTH'];

const unit = computed({
    get: (): string => props.modelValue.unit ?? '',
    set(value: string) {
        if (value === '') {
            emit('update:modelValue', { unit: null, interval: 1, until: null });

            return;
        }

        const next = value as RepeatUnit;

        emit('update:modelValue', {
            unit: next,
            // Re-clamped against the NEW unit: "every 30 days" switched
            // to months has to become "every 12 months", not a value the
            // server will reject.
            interval: Math.min(props.modelValue.interval || 1, MAX_INTERVAL[next]),
            until: props.modelValue.until,
        });
    },
});

const interval = computed({
    get: (): number => props.modelValue.interval,
    set(value: number | string) {
        const parsed = Math.trunc(Number(value));
        const ceiling = props.modelValue.unit ? MAX_INTERVAL[props.modelValue.unit] : 1;
        const clamped = Number.isFinite(parsed) ? Math.min(Math.max(parsed, 1), ceiling) : 1;

        emit('update:modelValue', { ...props.modelValue, interval: clamped });
    },
});

// datetime-local carries no timezone of its own — it is always whatever
// wall clock the browser shows. Converting on both edges through
// `props.timezone` (the profile zone, never the OS zone) is what keeps
// this field agreeing with the publish-date picker directly above it.
// See zonedDateTime.ts's docblock.
const until = computed({
    get: (): string => (props.modelValue.until ? utcIsoToZonedInput(props.modelValue.until, props.timezone) : ''),
    set(value: string) {
        emit('update:modelValue', {
            ...props.modelValue,
            until: value ? zonedInputToUtcIso(value, props.timezone) : null,
        });
    },
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex flex-col gap-1">
            <label for="repeat-unit" class="text-sm font-medium text-text">{{ t('editor.repeat.label') }}</label>
            <select
                id="repeat-unit"
                v-model="unit"
                data-testid="repeat-unit"
                class="w-full rounded-xl border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary sm:w-auto"
            >
                <option value="">{{ t('editor.repeat.never') }}</option>
                <option v-for="option in UNITS" :key="option" :value="option">
                    {{ t(`editor.repeat.unit.${option}`) }}
                </option>
            </select>
        </div>

        <div v-if="modelValue.unit" class="flex flex-wrap items-end gap-3">
            <div class="flex flex-col gap-1">
                <label for="repeat-interval" class="text-xs font-medium text-muted">{{ t('editor.repeat.every') }}</label>
                <input
                    id="repeat-interval"
                    v-model="interval"
                    data-testid="repeat-interval"
                    type="number"
                    min="1"
                    :max="MAX_INTERVAL[modelValue.unit]"
                    class="w-24 rounded-xl border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                />
            </div>

            <div class="flex flex-col gap-1">
                <label for="repeat-until" class="text-xs font-medium text-muted">{{ t('editor.repeat.until') }}</label>
                <input
                    id="repeat-until"
                    v-model="until"
                    data-testid="repeat-until"
                    type="datetime-local"
                    class="rounded-xl border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                />
            </div>
        </div>

        <!-- Not a warning tint: --warning is spoken for by the plan-limit
             toast on this very screen (color.md §3.8), and this is an
             explanation, not a problem. -->
        <p v-if="modelValue.unit" class="text-xs text-muted">{{ t('editor.repeat.hint') }}</p>
    </div>
</template>
