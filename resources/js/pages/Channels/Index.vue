<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import AppLayout from '../../layouts/AppLayout.vue';
import Toast from '../../components/ui/Toast.vue';
import ChannelCard from '../../components/channels/ChannelCard.vue';
import ProviderCard from '../../components/channels/ProviderCard.vue';
import ConnectProviderModal from '../../components/channels/ConnectProviderModal.vue';
import ChannelSettingsDrawer from '../../components/channels/ChannelSettingsDrawer.vue';
import ChannelLimitMeter from '../../components/billing/ChannelLimitMeter.vue';
import { PopupBlockedError, useChannelConnect } from '../../composables/useChannelConnect';
import { useIntegrationsStore, type Integration, type Provider } from '../../stores/integrations';
import { useAuthStore } from '../../stores/auth';
import { useOrganizationStore } from '../../stores/organization';

const { t } = useI18n();
const store = useIntegrationsStore();
const auth = useAuthStore();
const organization = useOrganizationStore();
const { startOAuthConnect } = useChannelConnect();

const loading = ref(false);
const errorMessage = ref<string | null>(null);
/** Neutral confirmation that the login tab opened — a fast-connect provider never shows a modal, so this is the only feedback the user gets before they switch tabs. */
const noticeMessage = ref<string | null>(null);

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

/**
 * How many channels the organization holds per network, built once per
 * change rather than filtered per card: the catalogue block renders a
 * card for every provider the build ships, and a per-card `filter()`
 * would walk the whole channel list once per network for no reason.
 */
const connectedCounts = computed(() =>
    store.list.reduce<Record<string, number>>((counts, integration) => {
        counts[integration.provider_identifier] = (counts[integration.provider_identifier] ?? 0) + 1;

        return counts;
    }, {}),
);

const connectOpen = ref(false);
const connectProvider = ref<Provider | undefined>(undefined);
// Task 36 fix round 1, Critical 2: which specific channel is being
// reconnected, so EnforcePlanLimit's pre-check can tell a repair of an
// existing channel apart from a genuinely new connect — see
// ConnectProviderModal.vue's preselectIntegrationId docblock.
const connectIntegrationId = ref<string | undefined>(undefined);

/**
 * Task 37: the single entry point for both a fresh connect (from a
 * catalogue card) and a reconnect (from an already-held, broken channel).
 * A fast-connect oauth provider — declares no `auth_fields`, so there is
 * nothing to ask for before the redirect — never opens the modal at all:
 * asking anything at all would be a dialog with one button in it, so its
 * login opens directly in a new tab instead.
 */
function beginConnect(provider: Provider, integrationId?: string): void {
    if (provider.auth_type === 'oauth' && provider.auth_fields.length === 0) {
        void openLoginTab(provider, integrationId);

        return;
    }

    connectProvider.value = provider;
    connectIntegrationId.value = integrationId;
    connectOpen.value = true;
}

async function openLoginTab(provider: Provider, integrationId?: string): Promise<void> {
    errorMessage.value = null;
    noticeMessage.value = null;

    const reconnectParams = integrationId ? { integration_id: integrationId } : {};

    try {
        await startOAuthConnect(provider, reconnectParams);
        noticeMessage.value = t('channels.available.openingLogin');
    } catch (e) {
        if (e instanceof PopupBlockedError) {
            errorMessage.value = t('channels.available.popupBlocked');

            return;
        }

        // Task 36 fix round 1, Minor 5: a 402 already shows the global
        // warning toast (api/client.ts's response interceptor) — this
        // page's own --danger line must not ALSO fire for the same event
        // (color.md §3.8). Every other failure keeps its line.
        if (!(isAxiosError(e) && e.response?.status === 402)) {
            errorMessage.value = isAxiosError(e) && e.response?.status === 403
                ? t('channels.connectModal.permissionDenied')
                : t('channels.connectModal.error');
        }
    }
}

function openReconnect(integration: Integration): void {
    const provider = providerFor(integration.provider_identifier);

    if (!provider) {
        return;
    }

    beginConnect(provider, integration.id);
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
/** Channels that need reconnecting — each gets a banner above the list. */
const brokenChannels = computed(() => store.list.filter((integration) => integration.refresh_needed));

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
        <!-- Left-aligned, not centred: the two blocks are a reading
             column, and `mx-auto` parked them mid-screen with the nav
             rail already eating the left edge, so nothing on the page
             shared a starting x. `max-w-3xl` stays — it caps the line
             length and the card grid, it does not centre anything. -->
        <div class="flex max-w-6xl flex-col gap-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <span class="font-mono text-xs text-muted">{{ t('channels.connectedCount', { count: store.list.length }) }}</span>
                    <h1 class="text-[26px] font-semibold leading-tight tracking-tight text-text">{{ t('channels.title') }}</h1>
                </div>
                <ChannelLimitMeter :refresh-key="store.list.length" />
            </div>

            <!-- «Кобальт»: a broken channel is announced above the list, not
                 only on its card — scheduled posts into it will not go out
                 until it is reconnected, which is worth a banner. -->
            <div
                v-for="broken in brokenChannels"
                :key="broken.id"
                role="alert"
                data-test="reconnect-banner"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-danger-border bg-danger-soft px-4 py-3 text-sm"
            >
                <svg class="h-[18px] w-[18px] shrink-0 text-danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16h.01" /></svg>
                <span class="min-w-0 flex-1 text-text">
                    <strong class="font-semibold text-danger">{{ t('channels.reconnectBanner.title', { name: broken.name }) }}</strong>
                    {{ t('channels.reconnectBanner.body') }}
                </span>
                <button
                    type="button"
                    class="rounded-xl bg-danger px-3.5 py-2 text-[13px] font-semibold text-on-danger hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger"
                    @click="openReconnect(broken)"
                >
                    {{ t('channels.actions.reconnect') }}
                </button>
            </div>

            <Toast v-if="errorMessage" variant="danger" @dismiss="errorMessage = null">{{ errorMessage }}</Toast>
            <Toast v-else-if="noticeMessage" variant="neutral" @dismiss="noticeMessage = null">{{ noticeMessage }}</Toast>

            <!-- What this organization already holds. -->
            <section class="flex flex-col gap-3">
                <h2 class="text-base font-semibold text-text">{{ t('channels.connected.title') }}</h2>

                <p v-if="!loading && store.list.length === 0" class="rounded-lg border border-border bg-surface px-4 py-6 text-sm text-muted">{{ t('channels.empty') }}</p>

                <div class="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">
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
            </section>

            <!-- What it could hold. Every network the build ships, whether
                 or not it is already connected — see ProviderCard's
                 docblock for why a connected network keeps its card. The
                 screen's only connect trigger now lives here: the old
                 header button opened the very same picker, and one screen
                 does not need the same action twice. -->
            <section class="flex flex-col gap-3">
                <div>
                    <h2 class="text-base font-semibold text-text">{{ t('channels.available.title') }}</h2>
                    <p class="mt-0.5 text-[13px] text-muted">{{ t('channels.available.subtitle') }}</p>
                </div>

                <div class="grid gap-2.5 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">
                    <ProviderCard
                        v-for="provider in store.providers"
                        :key="provider.identifier"
                        data-test="provider-card"
                        :provider="provider"
                        :connected-count="connectedCounts[provider.identifier] ?? 0"
                        @connect="beginConnect"
                    />
                </div>
            </section>
        </div>

        <ConnectProviderModal
            :open="connectOpen"
            :provider="connectProvider ?? null"
            :preselect-integration-id="connectIntegrationId"
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
