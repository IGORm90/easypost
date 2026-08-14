<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '../ui/Card.vue';
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

/** `null` max means unlimited, which is not the same as "0 left". */
function remainingLabel(limit: LimitUsage): string {
    if (limit.max === null) {
        return t('settings.plan.unlimited');
    }

    return t('settings.plan.ofMax', { current: limit.current, max: limit.max });
}
</script>

<template>
    <Card v-if="plan || failed" data-test="plan-usage">
        <div class="flex flex-col gap-3 p-3">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-text">{{ t('settings.plan.title') }}</h2>
                <span v-if="plan" data-test="plan-name" class="text-xs text-muted">
                    {{ t('settings.plan.current', { plan: plan.plan }) }}
                </span>
            </div>

            <p v-if="failed" class="text-sm text-muted">{{ t('settings.plan.loadError') }}</p>

            <dl v-else-if="plan" class="flex flex-col gap-2 text-sm">
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-muted">{{ t('settings.plan.channels') }}</dt>
                    <dd data-test="usage-channels" class="text-text">{{ remainingLabel(plan.usage.channels) }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-muted">
                        {{ t('settings.plan.postsPerMonth') }}
                        <!-- The UTC month the count resets on, reported by
                             the API rather than guessed at here. -->
                        <span v-if="plan.usage.posts_per_month.period" class="text-xs">
                            ({{ plan.usage.posts_per_month.period }})
                        </span>
                    </dt>
                    <dd data-test="usage-posts" class="text-text">{{ remainingLabel(plan.usage.posts_per_month) }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-muted">{{ t('settings.plan.mediaStorage') }}</dt>
                    <dd data-test="usage-media" class="text-text">{{ remainingLabel(plan.usage.media_storage_mb) }}</dd>
                </div>
            </dl>
        </div>
    </Card>
</template>
