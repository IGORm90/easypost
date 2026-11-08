<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '../../api/client';
import { useOrganizationStore } from '../../stores/organization';

/**
 * What this organization's plan allows, and how much of it is spent.
 *
 * `PlanLimitService::usage()` was built in Task 36 and described as being
 * "for the UI", then never exposed or rendered — so `LimitToast` could
 * tell a user they had hit a limit while nothing anywhere told them they
 * were approaching one. Final review, batch 4 (coverage and gates).
 *
 * Read-only by design: plans are set by an operator running `php artisan
 * org:plan` (see docs/DEPLOYMENT.md — there is no billing, no checkout,
 * no self-serve upgrade), so offering an "upgrade" affordance here would
 * promise something the product cannot do.
 */
interface LimitUsage {
    current: number;
    /** null means unlimited on this plan — not zero, and not unknown. */
    max: number | null;
    period?: string;
}

interface PlanUsage {
    plan: string;
    usage: {
        channels: LimitUsage;
        posts_per_month: LimitUsage;
        media_storage_mb: LimitUsage;
    };
}

const { t } = useI18n();
const organization = useOrganizationStore();

const plan = ref<PlanUsage | null>(null);
const failed = ref(false);

async function load(): Promise<void> {
    try {
        const { data } = await api.get<PlanUsage>('/organizations/current/plan');
        plan.value = data;
        failed.value = false;
    } catch {
        plan.value = null;
        failed.value = true;
    }
}

onMounted(load);

// Same "switching orgs refreshes dependent data" pattern the rest of the
// app uses — usage is per-organization and would otherwise go stale.
watch(
    () => organization.current?.id,
    (id, previousId) => {
        if (id && id !== previousId) {
            load();
        }
    },
);

function percent(limit: LimitUsage): number {
    return limit.max ? Math.min(100, Math.round((limit.current / limit.max) * 100)) : 0;
}

function barClass(limit: LimitUsage): string {
    return percent(limit) > 85 ? 'bg-warning' : 'bg-primary';
}

/** `null` max means unlimited, which is not the same as "0 left". */
function remainingLabel(limit: LimitUsage): string {
    if (limit.max === null) {
        return t('settings.plan.unlimited');
    }

    return t('settings.plan.ofMax', { current: limit.current, max: limit.max });
}
</script>

<template>
    <!-- «Кобальт»: each limit is a label, an "N из M" in mono and a bar
         that turns --warning past 85%. The bars are decoration for the
         numbers beside them (aria-hidden): the numbers carry the meaning. -->
    <section v-if="plan || failed" data-test="plan-usage" class="flex flex-col gap-3.5 rounded-lg border border-border bg-surface p-6">
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-text">{{ t('settings.plan.title') }}</h2>
            <span v-if="plan" data-test="plan-name" class="rounded bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover">
                {{ t('settings.plan.current', { plan: plan.plan }) }}
            </span>
        </div>

        <p v-if="failed" class="text-sm text-muted">{{ t('settings.plan.loadError') }}</p>

        <dl v-else-if="plan" class="flex flex-col gap-3.5 text-[13px]">
            <div class="flex flex-col gap-1.5">
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-text">{{ t('settings.plan.channels') }}</dt>
                    <dd data-test="usage-channels" class="font-mono text-muted">{{ remainingLabel(plan.usage.channels) }}</dd>
                </div>
                    <div v-if="plan.usage.channels.max !== null" class="h-1.5 overflow-hidden rounded-full bg-border" aria-hidden="true">
                        <div class="h-full rounded-full" :class="barClass(plan.usage.channels)" :style="{ width: `${percent(plan.usage.channels)}%` }" />
                    </div>
            </div>
            <div class="flex flex-col gap-1.5">
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-text">
                        {{ t('settings.plan.postsPerMonth') }}
                        <!-- The UTC month the count resets on, reported by
                             the API rather than guessed at here. -->
                        <span v-if="plan.usage.posts_per_month.period" class="text-xs text-muted">
                            ({{ plan.usage.posts_per_month.period }})
                        </span>
                    </dt>
                    <dd data-test="usage-posts" class="font-mono text-muted">{{ remainingLabel(plan.usage.posts_per_month) }}</dd>
                </div>
                    <div v-if="plan.usage.posts_per_month.max !== null" class="h-1.5 overflow-hidden rounded-full bg-border" aria-hidden="true">
                        <div class="h-full rounded-full" :class="barClass(plan.usage.posts_per_month)" :style="{ width: `${percent(plan.usage.posts_per_month)}%` }" />
                    </div>
            </div>
            <div class="flex flex-col gap-1.5">
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-text">{{ t('settings.plan.mediaStorage') }}</dt>
                    <dd data-test="usage-media" class="font-mono text-muted">{{ remainingLabel(plan.usage.media_storage_mb) }}</dd>
                </div>
                    <div v-if="plan.usage.media_storage_mb.max !== null" class="h-1.5 overflow-hidden rounded-full bg-border" aria-hidden="true">
                        <div class="h-full rounded-full" :class="barClass(plan.usage.media_storage_mb)" :style="{ width: `${percent(plan.usage.media_storage_mb)}%` }" />
                    </div>
            </div>
        </dl>
    </section>
</template>
