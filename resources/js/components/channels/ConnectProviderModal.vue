<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import Modal from '../ui/Modal.vue';
import Button from '../ui/Button.vue';
import Input from '../ui/Input.vue';
import { useIntegrationsStore, type Integration, type Provider } from '../../stores/integrations';

/**
 * Connects a channel, generically across every provider's `auth_type`
 * (never branching on provider identifier to decide *which flow* to
 * run — only `auth_type` decides that, per this task's brief).
 *
 * The one place this component *is* provider-aware is the small local
 * field maps below (`OAUTH_PARAM_FIELDS`/`MANUAL_FIELD_DEFS`): the API
 * reports auth_type but not what a manual provider's credential form
 * should contain (Telegram wants bot_token+chat_id, Bluesky wants
 * handle+app_password) or which OAuth providers need input collected
 * *before* the redirect can even be requested (Mastodon's instance
 * host — POST .../auth-url itself needs it). There is no way to derive
 * that shape from `GET /api/providers` today, so it lives here as a
 * small, additive lookup table — a provider newly implementing
 * ManualTokenAuth without an entry here still gets a working (if
 * generic, single-field) form via FALLBACK_MANUAL_FIELDS, and a new
 * OAuth provider needing no pre-redirect params needs no entry at all.
 */
const props = defineProps<{
    open: boolean;
    providers: Provider[];
    /** Pre-selects a provider (skipping the picker) — used by the "Reconnect" flow on an already-connected, refresh_needed channel. */
    preselect?: string;
    /**
     * The specific channel being reconnected (Task 36 fix round 1,
     * Critical 2). Sent as `integration_id` on `POST .../auth-url` so
     * `EnforcePlanLimit`'s reconnect-aware pre-check can recognise this
     * as a repair of an existing channel, not a new one — otherwise an
     * organization already at its channel limit gets a 402 on the only
     * path this UI offers to fix a broken channel, even though
     * `IntegrationService::connect()` itself always allows it.
     */
    preselectIntegrationId?: string;
}>();

const emit = defineEmits<{ close: []; connected: [Integration] }>();

const { t } = useI18n();
const store = useIntegrationsStore();

interface FieldDef {
    key: string;
    labelKey: string;
    type: 'text' | 'password';
}

const OAUTH_PARAM_FIELDS: Record<string, FieldDef[]> = {
    mastodon: [{ key: 'instance', labelKey: 'channels.connectModal.fields.instance', type: 'text' }],
};

const MANUAL_FIELD_DEFS: Record<string, FieldDef[]> = {
    telegram: [
        { key: 'bot_token', labelKey: 'channels.connectModal.fields.botToken', type: 'password' },
        { key: 'chat_id', labelKey: 'channels.connectModal.fields.chatId', type: 'text' },
    ],
    bluesky: [
        { key: 'handle', labelKey: 'channels.connectModal.fields.handle', type: 'text' },
        { key: 'app_password', labelKey: 'channels.connectModal.fields.appPassword', type: 'password' },
    ],
};

const FALLBACK_MANUAL_FIELDS: FieldDef[] = [{ key: 'token', labelKey: 'channels.connectModal.fields.token', type: 'password' }];

const selected = ref<Provider | null>(null);
const values = ref<Record<string, string>>({});
const submitting = ref(false);
const error = ref<string | null>(null);

const fields = computed<FieldDef[]>(() => {
    if (!selected.value) {
        return [];
    }

    if (selected.value.auth_type === 'manual') {
        return MANUAL_FIELD_DEFS[selected.value.identifier] ?? FALLBACK_MANUAL_FIELDS;
    }

    return OAUTH_PARAM_FIELDS[selected.value.identifier] ?? [];
});

function reset(): void {
    selected.value = null;
    values.value = {};
    submitting.value = false;
    error.value = null;
}

function select(provider: Provider): void {
    selected.value = provider;
    values.value = {};
    error.value = null;
}

watch(
    () => props.open,
    (open) => {
        if (!open) {
            reset();

            return;
        }

        const match = props.preselect ? props.providers.find((p) => p.identifier === props.preselect) : undefined;

        if (match) {
            select(match);
        }
    },
    { immediate: true },
);

function close(): void {
    emit('close');
}

async function submit(): Promise<void> {
    if (!selected.value) {
        return;
    }

    const provider = selected.value;
    error.value = null;
    submitting.value = true;

    // Only present for the "Reconnect" flow (see preselectIntegrationId's
    // docblock) — omitted entirely for a genuinely new connect, so
    // EnforcePlanLimit's reconnect-aware check has nothing to match and
    // falls back to its normal channel-count check, exactly as before.
    const reconnectParams = props.preselectIntegrationId ? { integration_id: props.preselectIntegrationId } : {};

    try {
        if (provider.auth_type === 'manual') {
            // auth-url still mints the CSRF-bound `state` every callback
            // requires — manual providers never follow the `url` it
            // returns (see ManualTokenAuth's contract), but the state
            // itself is not optional.
            const { state } = await store.connect(provider.identifier, { ...reconnectParams });
            const integration = await store.completeCallback(provider.identifier, { state, ...values.value });
            emit('connected', integration);
            close();

            return;
        }

        const { url, state, extra } = await store.connect(provider.identifier, { ...values.value, ...reconnectParams });

        // Round-tripped generically: whatever `extra` the backend
        // returned (Mastodon's `{ instance }`, or nothing for providers
        // that don't need any) is stashed as opaque data and handed back
        // untouched by pages/Integrations/Callback.vue — this component
        // never inspects what's inside it.
        sessionStorage.setItem('channel_connect_pending', JSON.stringify({ provider: provider.identifier, state, extra }));
        window.location.assign(url);
    } catch (e) {
        // Task 36 fix round 1, Minor 5: a 402 already shows the global
        // warning toast (api/client.ts's response interceptor) — this
        // local --danger line must not ALSO fire for the same event
        // (color.md §3.8, and see EnforcePlanLimit's docblock for why
        // this route can 402 here even for a genuine reconnect that
        // names no live channel). Every other failure keeps its line.
        if (!(isAxiosError(e) && e.response?.status === 402)) {
            error.value = isAxiosError(e) && e.response?.status === 403
                ? t('channels.connectModal.permissionDenied')
                : t('channels.connectModal.error');
        }

        submitting.value = false;
    }
}
</script>

<template>
    <Modal :open="open" :title="t('channels.connectModal.title')" @close="close">
        <div v-if="!selected" class="flex flex-col gap-2">
            <p class="text-sm text-muted">{{ t('channels.connectModal.chooseProvider') }}</p>
            <button
                v-for="provider in providers"
                :key="provider.identifier"
                type="button"
                class="rounded-xl border border-border px-3 py-2 text-left text-sm text-text hover:bg-surface-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                @click="select(provider)"
            >
                {{ provider.name }}
            </button>
        </div>

        <form v-else class="flex flex-col gap-4" @submit.prevent="submit">
            <p v-if="selected.auth_type === 'oauth' && fields.length === 0" class="text-sm text-muted">
                {{ t('channels.connectModal.oauthIntro', { name: selected.name }) }}
            </p>

            <Input
                v-for="field in fields"
                :key="field.key"
                :id="`connect-${field.key}`"
                v-model="values[field.key]"
                :type="field.type"
                :label="t(field.labelKey)"
                required
            />

            <p v-if="error" class="text-sm text-danger">{{ error }}</p>

            <div class="flex gap-2">
                <Button v-if="!preselect" type="button" variant="ghost" @click="selected = null">
                    {{ t('channels.connectModal.back') }}
                </Button>
                <Button type="submit" variant="primary" :loading="submitting">
                    {{ selected.auth_type === 'manual' ? t('channels.connectModal.manualSubmit') : t('channels.connectModal.connect') }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
