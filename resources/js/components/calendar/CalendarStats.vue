<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * The four counters above the «Кобальт» calendar. They count the PostGroups
 * the calendar already loaded for the visible range — not a separate API
 * call — so a counter can never disagree with the cards under it.
 *
 * QUEUE is folded into "scheduled": from the user's side a post being
 * handed to the network right now is still a post that is going out, and
 * a fifth counter for a state that lasts seconds would only add noise.
 */
const props = defineProps<{ entries: Array<{ state: string }> }>();
const emit = defineEmits<{ 'show-errors': [] }>();

const { t } = useI18n();

const stats = computed(() => {
    const count = (states: string[]) => props.entries.filter((entry) => states.includes(entry.state)).length;
    const errors = count(['ERROR']);

    return [
        { key: 'scheduled', value: count(['SCHEDULED', 'QUEUE']), tone: 'border-border bg-surface', label: 'text-muted' },
        { key: 'published', value: count(['PUBLISHED']), tone: 'border-border bg-surface', label: 'text-muted' },
        { key: 'drafts', value: count(['DRAFT']), tone: 'border-border bg-surface', label: 'text-muted' },
        {
            key: 'errors',
            value: errors,
            // Red only when something failed: a permanently red "0" would
            // teach the eye to ignore the one counter that matters.
            tone: errors > 0 ? 'border-danger-border bg-danger-soft text-danger' : 'border-border bg-surface',
            label: errors > 0 ? 'text-danger' : 'text-muted',
        },
    ];
});
</script>

<template>
    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div v-for="stat in stats" :key="stat.key" :data-test="`stat-${stat.key}`" class="rounded-lg border px-4 py-3" :class="stat.tone">
            <dt class="text-xs" :class="stat.label">{{ t(`calendar.stats.${stat.key}`) }}</dt>
            <dd class="mt-1 flex items-baseline gap-2.5">
                <span data-test="stat-value" class="text-2xl font-semibold">{{ stat.value }}</span>
                <button
                    v-if="stat.key === 'errors' && stat.value > 0"
                    type="button"
                    class="text-[13px] font-semibold text-danger underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-danger"
                    @click="emit('show-errors')"
                >
                    {{ t('calendar.stats.show') }}
                </button>
            </dd>
        </div>
    </dl>
</template>
