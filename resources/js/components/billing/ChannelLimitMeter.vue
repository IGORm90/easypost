<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '../../api/client';
import { useOrganizationStore } from '../../stores/organization';

/**
 * The channel allowance, shown on the Channels page where the decision to
 * connect one more is made. Reads the same endpoint as PlanUsagePanel —
 * PlanController reports limits, it never enforces them (AGENTS.md, Plan
 * limits), so this is display only; EnforcePlanLimit still has the last
 * word and LimitToast still explains a refusal.
 *
 * `refreshKey` is how the page says "the count changed" (a channel was
 * connected or removed) without this component knowing about the
 * integrations store.
 */
const props = withDefaults(defineProps<{ refreshKey?: number }>(), { refreshKey: 0 });

interface LimitUsage {
    current: number;
    max: number | null;
}

const { t } = useI18n();
const organization = useOrganizationStore();
const usage = ref<LimitUsage | null>(null);

async function load(): Promise<void> {
    try {
        const { data } = await api.get<{ usage: { channels: LimitUsage } }>('/organizations/current/plan');
        usage.value = data.usage.channels;
    } catch {
        // A meter that cannot be drawn is simply left out: the channel
        // list is the page, and it does not depend on this.
        usage.value = null;
    }
}

onMounted(load);
watch(() => props.refreshKey, load);
watch(
    () => organization.current?.id,
    (id, previousId) => {
        if (id && id !== previousId) {
            load();
        }
    },
);

const percent = computed(() => {
    if (!usage.value || usage.value.max === null || usage.value.max === 0) {
        return 0;
    }

    return Math.min(100, Math.round((usage.value.current / usage.value.max) * 100));
});

// color.md: the bar turns to --warning past 85% — "running out" is a state
// to notice, not yet an error.
const fillClass = computed(() => (percent.value > 85 ? 'bg-warning' : 'bg-primary'));
</script>

<template>
    <div v-if="usage" class="flex w-56 flex-col gap-1.5" data-test="channel-limit">
        <div class="flex items-baseline justify-between gap-2 text-xs text-muted">
            <span id="channel-limit-label">{{ t('channels.limit.label') }}</span>
            <span v-if="usage.max !== null" class="font-mono">{{ usage.current }} / {{ usage.max }}</span>
            <span v-else>{{ t('settings.plan.unlimited') }}</span>
        </div>
        <div
            v-if="usage.max !== null"
            role="meter"
            aria-labelledby="channel-limit-label"
            aria-valuemin="0"
            :aria-valuemax="usage.max"
            :aria-valuenow="usage.current"
            class="h-1.5 overflow-hidden rounded-full bg-border"
        >
            <div data-test="meter-fill" class="h-full rounded-full" :class="fillClass" :style="{ width: `${percent}%` }" />
        </div>
    </div>
</template>
