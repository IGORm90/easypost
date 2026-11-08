<script setup lang="ts">
import { useI18n } from 'vue-i18n';

defineProps<{
    /** The sidebar and the phone menu style their links alike; the layout owns that class. */
    linkClass: string;
}>();

const { t } = useI18n();

/**
 * Server-rendered pages outside the SPA, so plain anchors: the client router
 * has no such routes, and a <router-link> would resolve to the SPA catch-all
 * and render nothing. Each is gated server-side by `viewMonitoring`; the
 * caller only offers them to a platform admin, which is a courtesy, not the
 * boundary.
 *
 * Labelled by the question each page answers — a platform admin looking for
 * "why did that post not go out" is not expected to know which of three
 * product names holds the answer.
 */
const LINKS = [
    { href: '/horizon', label: 'nav.monitoringQueues' },
    { href: '/pulse', label: 'nav.monitoringPerformance' },
    { href: '/log-viewer', label: 'nav.monitoringLogs' },
] as const;
</script>

<template>
    <p data-test="monitoring-heading" class="mt-3 px-2.5 text-[11px] font-semibold uppercase tracking-wider text-nav-muted">
        {{ t('nav.monitoring') }}
    </p>
    <!-- A separate application: its own tab keeps the SPA's place. -->
    <a
        v-for="link in LINKS"
        :key="link.href"
        data-test="monitoring-link"
        :href="link.href"
        target="_blank"
        rel="noopener"
        :class="linkClass"
    >
        {{ t(link.label) }}<span class="sr-only"> {{ t('nav.opensInNewTab') }}</span>
    </a>
</template>
