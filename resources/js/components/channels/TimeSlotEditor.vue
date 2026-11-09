<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
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

/**
 * «Кобальт» groups the slots by local weekday — the same rows as before,
 * just bucketed — keeping each slot's index into `modelValue` so an edit
 * or a removal still addresses exactly one stored value.
 */
const days = computed(() =>
    weekdayLabels.value.map((label, weekday) => ({
        weekday,
        label,
        slots: rows.value
            .map((row, index) => ({ ...row, index }))
            .filter((row) => row.weekday === weekday)
            .sort((a, b) => a.hour * 60 + a.minute - (b.hour * 60 + b.minute)),
    })),
);

function onTimeChange(index: number, event: Event): void {
    const row = rows.value[index];
    const [hour, minute] = (event.target as HTMLInputElement).value.split(':').map(Number);
    updateRow(index, row.weekday, hour ?? 0, minute ?? 0);
}

/**
 * Adds a slot to one weekday: 09:00 local, or the next whole hour that
 * day does not already hold — so pressing "+" twice gives two slots
 * instead of one silently deduplicated one.
 */
function addSlot(weekday: number): void {
    const taken = new Set(rows.value.filter((row) => row.weekday === weekday).map((row) => row.hour * 60 + row.minute));
    let hour = 9;

    while (taken.has(hour * 60) && hour < 23) {
        hour += 1;
    }

    const next = [...props.modelValue, localToUtcMinutes(weekday, hour, 0, props.timezone)];
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
    <div class="flex flex-col gap-2.5">
        <div class="flex items-baseline justify-between gap-2">
            <p class="text-xs text-muted">{{ t('channels.settings.timeSlotsHint', { tz: timezone }) }}</p>
            <span data-test="slot-total" class="shrink-0 font-mono text-xs text-muted">{{ t('channels.settings.slotCount', { count: modelValue.length }) }}</span>
        </div>

        <div class="overflow-hidden rounded-lg border border-border">
            <div
                v-for="day in days"
                :key="day.weekday"
                data-test="slot-day"
                class="flex items-center gap-3 border-b border-border px-3 py-2 last:border-b-0"
            >
                <span class="w-8 shrink-0 text-[13px] font-medium text-text">{{ day.label }}</span>
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                    <span
                        v-for="slot in day.slots"
                        :key="slot.minutes"
                        data-test="slot-chip"
                        class="inline-flex items-center gap-0.5 rounded border border-primary-soft bg-primary-soft py-0.5 pl-1.5 pr-0.5 text-primary-hover"
                    >
                        <label :for="`slot-time-${slot.index}`" class="sr-only">{{ t('channels.settings.time') }}, {{ day.label }}</label>
                        <input
                            :id="`slot-time-${slot.index}`"
                            type="time"
                            :value="timeString(slot.hour, slot.minute)"
                            class="w-[4.5rem] border-0 bg-primary-soft p-0 font-mono text-xs text-primary-hover focus:outline-none focus:ring-0"
                            @change="onTimeChange(slot.index, $event)"
                        />
                        <button
                            type="button"
                            class="flex h-5 w-5 items-center justify-center rounded text-primary-hover hover:bg-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            :aria-label="`${t('channels.settings.removeSlot')} ${day.label} ${timeString(slot.hour, slot.minute)}`"
                            @click="removeSlot(slot.index)"
                        >
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                        </button>
                    </span>
                    <span v-if="day.slots.length === 0" class="text-xs text-muted">{{ t('channels.settings.noSlots') }}</span>
                </div>
                <button
                    type="button"
                    data-test="slot-add"
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-xl border border-dashed border-border-strong text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :aria-label="`${t('channels.settings.addSlot')}: ${day.label}`"
                    @click="addSlot(day.weekday)"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                </button>
            </div>
        </div>
    </div>
</template>
