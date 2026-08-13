<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import AppLayout from '../../layouts/AppLayout.vue';
import Button from '../../components/ui/Button.vue';
import Toast from '../../components/ui/Toast.vue';
import ChannelCard from '../../components/channels/ChannelCard.vue';
import ConnectProviderModal from '../../components/channels/ConnectProviderModal.vue';
import ChannelSettingsDrawer from '../../components/channels/ChannelSettingsDrawer.vue';
import { useIntegrationsStore, type Integration } from '../../stores/integrations';
import { useAuthStore } from '../../stores/auth';
import { useOrganizationStore } from '../../stores/organization';

const { t } = useI18n();
const store = useIntegrationsStore();
const auth = useAuthStore();
const organization = useOrganizationStore();

const loading = ref(false);
const errorMessage = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;

    try {
        await Promise.all([store.fetch(), store.fetchProviders()]);
    } finally {
        loading.value = false;
    }
}

onMounted(load);
// Same "switching orgs refreshes dependent data" pattern as
// Settings/Team.vue — this page's channel list is per-organization.
watch(
    () => organization.current?.id,
    (id, previousId) => {
        if (id && id !== previousId) {
            load();
        }
    },
);

const providerFor = (identifier: string) => store.providers.find((p) => p.identifier === identifier);

const connectOpen = ref(false);
const reconnectProvider = ref<string | undefined>(undefined);

function openConnect(): void {
    reconnectProvider.value = undefined;
    connectOpen.value = true;
}

function openReconnect(integration: Integration): void {
    reconnectProvider.value = integration.provider_identifier;
    connectOpen.value = true;
}

function onConnected(): void {
    connectOpen.value = false;
}

const settingsTarget = ref<Integration | null>(null);

function openSettings(integration: Integration): void {
    settingsTarget.value = integration;
}

function closeSettings(): void {
    settingsTarget.value = null;
}

// Keeps the drawer's props in sync with the store after a background
// refresh (e.g. reconnect while the drawer happens to be open).
const activeIntegration = computed(() => (settingsTarget.value ? (store.list.find((i) => i.id === settingsTarget.value?.id) ?? null) : null));

async function toggle(integration: Integration): Promise<void> {
    errorMessage.value = null;

    try {
        await store.toggle(integration.id);
    } catch (e) {
        errorMessage.value = isAxiosError(e) && e.response?.status === 403
            ? t('channels.connectModal.permissionDenied')
            : t('channels.connectModal.error');
    }
}

async function remove(integration: Integration): Promise<void> {
    if (!window.confirm(t('channels.actions.removeConfirm', { name: integration.name }))) {
        return;
    }

    errorMessage.value = null;

    try {
        await store.remove(integration.id);
    } catch (e) {
        errorMessage.value = isAxiosError(e) && e.response?.status === 403
            ? t('channels.connectModal.permissionDenied')
            : t('channels.connectModal.error');
    }
}

const timezone = computed(() => auth.user?.timezone ?? 'UTC');
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex max-w-3xl flex-col gap-4">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold text-text">{{ t('channels.title') }}</h1>
                <!-- secondary, not primary: ConnectProviderModal's own
                     submit button is this screen's one primary action
                     once the modal is open (color.md §3.1 — at most one
                     primary element per screen), same reasoning as
                     Settings/Team.vue's invite trigger. -->
                <Button variant="secondary" @click="openConnect">{{ t('channels.connect') }}</Button>
            </div>

            <Toast v-if="errorMessage" variant="danger" @dismiss="errorMessage = null">{{ errorMessage }}</Toast>

            <p v-if="!loading && store.list.length === 0" class="text-sm text-muted">{{ t('channels.empty') }}</p>

            <div class="flex flex-col gap-3">
                <ChannelCard
                    v-for="integration in store.list"
                    :key="integration.id"
                    :integration="integration"
                    :provider="providerFor(integration.provider_identifier)"
                    @settings="openSettings(integration)"
                    @toggle="toggle(integration)"
                    @remove="remove(integration)"
                    @reconnect="openReconnect(integration)"
                />
            </div>
        </div>

        <ConnectProviderModal
            :open="connectOpen"
            :providers="store.providers"
            :preselect="reconnectProvider"
            @close="connectOpen = false"
            @connected="onConnected"
        />

        <ChannelSettingsDrawer
            :open="Boolean(settingsTarget)"
            :integration="activeIntegration"
            :timezone="timezone"
            @close="closeSettings"
        />
    </AppLayout>
</template>
