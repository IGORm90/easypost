<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '../ui/Button.vue';
import { localToUtcMinutes, utcMinutesToLocal, WEEKDAY_COUNT } from '../../utils/timeSlots';

/**
 * Edits `minutes_from_week_start` values (0..10079, anchored to Monday
 * 00:00 UTC — see timeSlots.ts) as a list of weekday + time-of-day rows
 * in `timezone` (the signed-in user's profile timezone, per this task's
 * brief: raw minute counts are not something a human should have to
 * think in). `modelValue`/`update:modelValue` always carry the raw UTC
 * minute values — conversion is entirely internal to this component.
 */
const props = defineProps<{
    modelValue: number[];
    timezone: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [number[]] }>();

const { t } = useI18n();

const weekdayLabels = computed(() => Array.from({ length: WEEKDAY_COUNT }, (_, i) => t(`channels.weekdays.${i}`)));

const rows = computed(() => props.modelValue.map((minutes) => ({ minutes, ...utcMinutesToLocal(minutes, props.timezone) })));

function timeString(hour: number, minute: number): string {
    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

function updateRow(index: number, weekday: number, hour: number, minute: number): void {
    const next = [...props.modelValue];
    next[index] = localToUtcMinutes(weekday, hour, minute, props.timezone);
    emit('update:modelValue', dedupe(next));
}

function onWeekdayChange(index: number, event: Event): void {
    const row = rows.value[index];
    const weekday = Number((event.target as HTMLSelectElement).value);
    updateRow(index, weekday, row.hour, row.minute);
}

function onTimeChange(index: number, event: Event): void {
    const row = rows.value[index];
    const [hour, minute] = (event.target as HTMLInputElement).value.split(':').map(Number);
    updateRow(index, row.weekday, hour ?? 0, minute ?? 0);
}

function addSlot(): void {
    // A sensible, always-valid default: Monday 09:00 local — converted
    // to its UTC minute value immediately, same as any other row.
    const next = [...props.modelValue, localToUtcMinutes(0, 9, 0, props.timezone)];
    emit('update:modelValue', dedupe(next));
}

function removeSlot(index: number): void {
    const next = props.modelValue.filter((_, i) => i !== index);
    emit('update:modelValue', next);
}

// The backend rejects duplicate minutes_from_week_start values
// (UpdateIntegrationRequest's `distinct` rule) — deduping here means a
// user who picks a weekday/time combination that lands on an existing
// slot gets a silently merged row instead of a 422 on save.
function dedupe(values: number[]): number[] {
    return Array.from(new Set(values));
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <p class="text-xs text-muted">{{ t('channels.settings.timeSlotsHint', { tz: timezone }) }}</p>

        <div v-for="(row, index) in rows" :key="`${row.minutes}-${index}`" class="flex items-center gap-2">
            <label :for="`slot-weekday-${index}`" class="sr-only">{{ t('channels.settings.weekday') }}</label>
            <select
                :id="`slot-weekday-${index}`"
                :value="row.weekday"
                class="rounded-xl border border-border-strong bg-surface px-2 py-1.5 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                @change="onWeekdayChange(index, $event)"
            >
                <option v-for="(label, weekday) in weekdayLabels" :key="weekday" :value="weekday">{{ label }}</option>
            </select>

            <label :for="`slot-time-${index}`" class="sr-only">{{ t('channels.settings.time') }}</label>
            <input
                :id="`slot-time-${index}`"
                type="time"
                :value="timeString(row.hour, row.minute)"
                class="rounded-xl border border-border-strong bg-surface px-2 py-1.5 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                @change="onTimeChange(index, $event)"
            />

            <Button variant="ghost" type="button" @click="removeSlot(index)">
                {{ t('channels.settings.removeSlot') }}
            </Button>
        </div>

        <Button variant="secondary" type="button" class="self-start" @click="addSlot">
            {{ t('channels.settings.addSlot') }}
        </Button>
    </div>
</template>
