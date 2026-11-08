<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '../ui/Button.vue';
import { resolveProviderIcon } from '../previews/registry';
import type { Provider } from '../../stores/integrations';

/**
 * One network the build can connect to, in the catalogue block at the
 * bottom of /channels. Unlike ChannelCard this describes a *capability*,
 * not something the organization owns — which is why it never disappears
 * once connected: uniqueness in IntegrationService::connect() is
 * (organization, provider_identifier, internal_id), so a second Telegram
 * bot or a second Bluesky account is a normal setup rather than a
 * duplicate. It grows a count instead.
 *
 * No branch on `provider.identifier` anywhere here — the mark comes from
 * `resolveProviderIcon()`, which is the only place allowed to know that a
 * given identifier draws as a given component (see previews/registry.ts
 * and resources/js/__tests__/providerIsolation.spec.ts).
 */
const props = defineProps<{
    provider: Provider;
    /** How many channels of this network the organization already holds; 0 hides the badge entirely. */
    connectedCount: number;
}>();

const emit = defineEmits<{ connect: [Provider] }>();

const { t } = useI18n();

const icon = computed(() => resolveProviderIcon(props.provider.identifier));
</script>

<template>
    <!-- «Кобальт» network tile: mark and name on top, the connected count
         and the connect button on one line underneath. -->
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-surface p-3.5">
        <div class="flex items-center gap-2.5">
            <!-- The brand mark's one non-token colour (color.md §7) always
                 sits on --surface-2: the same neutral ground in both
                 themes, so a logo's contrast is settled once. -->
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-surface-2 text-muted">
                <component :is="icon" class="h-5 w-5" />
            </div>
            <p class="truncate text-sm font-semibold text-text">{{ provider.name }}</p>
        </div>
        <div class="flex items-center justify-between gap-2">
            <span class="text-xs text-muted">
                <template v-if="connectedCount > 0">{{ t('channels.available.connectedCount', { count: connectedCount }) }}</template>
            </span>
            <!-- Secondary, not primary: the connect modal's own submit is
                 this screen's single primary action once it opens
                 (color.md §3.1). -->
            <Button
                variant="secondary"
                :aria-label="t('channels.available.connectAria', { name: provider.name })"
                @click="emit('connect', provider)"
            >
                {{ t('channels.available.connect') }}
            </Button>
        </div>
    </div>
</template>
