<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import { resolveProviderIcon } from '../previews/registry';
import type { Integration, Provider } from '../../stores/integrations';

const props = defineProps<{
    integration: Integration;
    provider: Provider | undefined;
}>();

const emit = defineEmits<{
    settings: [];
    toggle: [];
    remove: [];
    reconnect: [];
}>();

const { t } = useI18n();

const providerName = computed(() => props.provider?.name ?? props.integration.provider_identifier);

// Avatars never carry primary — color.md §3.1/§3.2: they're decorative
// identity, not the screen's action.
//
// The fallback for a channel the network gave us no picture for used to
// be a single-letter monogram, which told a reader nothing: every
// Telegram channel drew the same "T". The network's own mark at least
// says which network this is. Resolved through the registry, never by
// branching on the identifier here (previews/registry.ts).
const icon = computed(() => resolveProviderIcon(props.integration.provider_identifier));

/** Posting slots per week — `time_slots` only arrives on the full Integration, so absent means "unknown", not zero. */
const slotCount = computed(() => props.integration.time_slots?.length ?? null);
</script>

<template>
    <!-- «Кобальт» channel card: mark, name and network on top with the
         status badge pinned right, the client and slot count under it, and
         the actions in a footer row with "Remove" set apart on the far
         right. A broken channel gets a danger frame as well as its badge. -->
    <article
        class="flex flex-col gap-3 rounded-lg border bg-surface p-4"
        :class="integration.refresh_needed ? 'border-danger-border' : 'border-border'"
    >
        <div class="flex items-center gap-3">
            <img
                v-if="integration.picture"
                :src="integration.picture"
                :alt="providerName"
                class="h-10 w-10 shrink-0 rounded-lg object-cover"
            />
            <!-- --surface-2, not --accent-soft: the mark may carry a brand
                 colour (color.md §7) and a neutral ground keeps that
                 contrast identical in both themes — the same tile
                 ProviderCard uses. -->
            <div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-surface-2 text-muted" aria-hidden="true">
                <component :is="icon" class="h-6 w-6" />
            </div>
            <div class="flex min-w-0 flex-1 flex-col">
                <p class="truncate text-sm font-semibold text-text">{{ integration.name }}</p>
                <p class="truncate text-xs text-muted">{{ providerName }}</p>
            </div>
            <!-- refresh_needed always wins over the plain disabled badge: a
                 broken channel must never read as merely switched off — the
                 word "reconnect" carries the meaning, not the red alone. -->
            <Badge v-if="integration.refresh_needed" status="danger">{{ t('channels.status.refreshNeeded') }}</Badge>
            <Badge v-else-if="integration.disabled" status="neutral">{{ t('channels.status.disabled') }}</Badge>
            <Badge v-else status="success">{{ t('channels.status.active') }}</Badge>
        </div>

        <p v-if="integration.group_name || integration.customer_name || slotCount !== null" class="flex flex-wrap gap-x-3.5 gap-y-1 text-xs text-muted">
            <span v-if="integration.customer_name">{{ t('channels.settings.customerName') }}: <span class="text-text">{{ integration.customer_name }}</span></span>
            <span v-if="integration.group_name">{{ t('channels.settings.groupName') }}: <span class="text-text">{{ integration.group_name }}</span></span>
            <span v-if="slotCount !== null">{{ t('channels.slotsPerWeek') }}: <span class="font-mono text-text">{{ slotCount }}</span></span>
        </p>

        <div class="flex flex-wrap items-center gap-1.5 border-t border-border pt-3">
            <Button v-if="integration.refresh_needed" variant="secondary" @click="emit('reconnect')">
                {{ t('channels.actions.reconnect') }}
            </Button>
            <Button variant="secondary" @click="emit('settings')">
                {{ t('channels.actions.settings') }}
            </Button>
            <Button v-if="!integration.refresh_needed" variant="secondary" @click="emit('toggle')">
                {{ integration.disabled ? t('channels.actions.enable') : t('channels.actions.disable') }}
            </Button>
            <button
                type="button"
                class="ml-auto rounded-xl px-2.5 py-2 text-sm font-medium text-danger hover:bg-danger-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-danger"
                @click="emit('remove')"
            >
                {{ t('channels.actions.remove') }}
            </button>
        </div>
    </article>
</template>
