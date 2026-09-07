<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '../ui/Card.vue';
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
</script>

<template>
    <Card>
        <div class="flex items-start gap-3">
            <img
                v-if="integration.picture"
                :src="integration.picture"
                :alt="providerName"
                class="h-10 w-10 shrink-0 rounded-full object-cover"
            />
            <!-- --surface-2, not --accent-soft: the mark may carry a
                 brand colour (color.md §6) and a neutral ground keeps that
                 contrast identical in both themes — the same tile
                 ProviderCard uses, so a channel and its network read as
                 the same thing. -->
            <div
                v-else
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface-2 text-muted"
                aria-hidden="true"
            >
                <component :is="icon" class="h-6 w-6" />
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate text-sm font-semibold text-text">{{ integration.name }}</p>
                    <Badge status="neutral">{{ providerName }}</Badge>
                </div>

                <p v-if="integration.group_name || integration.customer_name" class="mt-0.5 truncate text-xs text-muted">
                    <span v-if="integration.group_name">{{ integration.group_name }}</span>
                    <span v-if="integration.group_name && integration.customer_name"> · </span>
                    <span v-if="integration.customer_name">{{ integration.customer_name }}</span>
                </p>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <!-- refresh_needed always wins over the plain disabled
                         badge: a broken channel must never read as merely
                         switched off (Task 17 brief's explicit requirement,
                         color.md §3 "status never conveyed by colour alone"
                         — the word "reconnect" carries the meaning, not the
                         red alone). -->
                    <Badge v-if="integration.refresh_needed" status="danger">
                        {{ t('channels.status.refreshNeeded') }}
                    </Badge>
                    <Badge v-else-if="integration.disabled" status="neutral">
                        {{ t('channels.status.disabled') }}
                    </Badge>
                    <Badge v-else status="success">
                        {{ t('channels.status.active') }}
                    </Badge>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <Button v-if="integration.refresh_needed" variant="secondary" @click="emit('reconnect')">
                        {{ t('channels.actions.reconnect') }}
                    </Button>
                    <Button variant="ghost" @click="emit('settings')">
                        {{ t('channels.actions.settings') }}
                    </Button>
                    <Button v-if="!integration.refresh_needed" variant="ghost" @click="emit('toggle')">
                        {{ integration.disabled ? t('channels.actions.enable') : t('channels.actions.disable') }}
                    </Button>
                    <Button variant="ghost" @click="emit('remove')">
                        {{ t('channels.actions.remove') }}
                    </Button>
                </div>
            </div>
        </div>
    </Card>
</template>
