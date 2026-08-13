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

    try {
        if (provider.auth_type === 'manual') {
            // auth-url still mints the CSRF-bound `state` every callback
            // requires — manual providers never follow the `url` it
            // returns (see ManualTokenAuth's contract), but the state
            // itself is not optional.
            const { state } = await store.connect(provider.identifier, {});
            const integration = await store.completeCallback(provider.identifier, { state, ...values.value });
            emit('connected', integration);
            close();

            return;
        }

        const { url, state, extra } = await store.connect(provider.identifier, values.value);

        // Round-tripped generically: whatever `extra` the backend
        // returned (Mastodon's `{ instance }`, or nothing for providers
        // that don't need any) is stashed as opaque data and handed back
        // untouched by pages/Integrations/Callback.vue — this component
        // never inspects what's inside it.
        sessionStorage.setItem('channel_connect_pending', JSON.stringify({ provider: provider.identifier, state, extra }));
        window.location.assign(url);
    } catch (e) {
        error.value = isAxiosError(e) && e.response?.status === 403
            ? t('channels.connectModal.permissionDenied')
            : t('channels.connectModal.error');
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
