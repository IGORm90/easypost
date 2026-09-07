<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '../ui/Card.vue';
import Badge from '../ui/Badge.vue';
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

const emit = defineEmits<{ connect: [] }>();

const { t } = useI18n();

const icon = computed(() => resolveProviderIcon(props.provider.identifier));
</script>

<template>
    <Card>
        <div class="flex items-center gap-3">
            <!-- The brand mark's one non-token colour (color.md §6) always
                 sits on --surface-2: the same neutral ground in both
                 themes, so a logo's contrast is settled once rather than
                 once per theme. -->
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-2 text-muted">
                <component :is="icon" class="h-6 w-6" />
            </div>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-text">{{ provider.name }}</p>
                <Badge v-if="connectedCount > 0" status="neutral" class="mt-1">
                    {{ t('channels.available.connectedCount', { count: connectedCount }) }}
                </Badge>
            </div>

            <!-- Secondary, not primary: the connect modal's own submit is
                 this screen's single primary action once it opens
                 (color.md §3.1), and a grid of primary buttons would make
                 every network look like the recommended one. -->
            <Button
                variant="secondary"
                :aria-label="t('channels.available.connectAria', { name: provider.name })"
                @click="emit('connect')"
            >
                {{ t('channels.available.connect') }}
            </Button>
        </div>
    </Card>
</template>
