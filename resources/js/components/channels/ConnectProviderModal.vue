<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import Modal from '../ui/Modal.vue';
import Button from '../ui/Button.vue';
import Input from '../ui/Input.vue';
import { useIntegrationsStore, type Integration, type Provider, type ProviderAuthField } from '../../stores/integrations';

/**
 * Connects a channel, generically across every provider's `auth_type`
 * (never branching on provider identifier to decide *which flow* to
 * run — only `auth_type` decides that, per this task's brief).
 *
 * Final review, Important 10: this component used to carry its own
 * hardcoded per-provider field maps for exactly that — which credentials
 * a manual provider's form needs, and which OAuth providers need a value
 * collected before the redirect can even be requested. That is a
 * behaviour map keyed by provider name living outside the preview
 * registry, and the isolation guard missed it only because the keys were
 * unquoted. `GET /api/providers` now reports each provider's own
 * `auth_fields` declaration (DeclaresAuthFields), so the form is rendered
 * from what the server says, and a provider gaining or changing a field
 * needs no change here at all.
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

const { t, te } = useI18n();
const store = useIntegrationsStore();

/**
 * A manual provider that declares no fields still needs *something* asked
 * for — a credential form with no inputs cannot connect anything — so a
 * single generic token field stands in until that provider declares its
 * own shape. An OAuth provider declaring nothing simply needs nothing
 * before the redirect, which is the normal case.
 */
const FALLBACK_MANUAL_FIELDS: ProviderAuthField[] = [{ key: 'token', type: 'password' }];

const selected = ref<Provider | null>(null);
const values = ref<Record<string, string>>({});
const submitting = ref(false);
const error = ref<string | null>(null);

const fields = computed<ProviderAuthField[]>(() => {
    if (!selected.value) {
        return [];
    }

    if (selected.value.auth_fields.length > 0) {
        return selected.value.auth_fields;
    }

    return selected.value.auth_type === 'manual' ? FALLBACK_MANUAL_FIELDS : [];
});

/**
 * i18n label for a declared field key. The key is snake_case as the
 * provider declares it (`bot_token`); the message key is its camelCase
 * form, so copy already written for these fields keeps working and a
 * newly declared field falls back to its raw key rather than rendering
 * vue-i18n's missing-key placeholder as if it were a label.
 */
function fieldLabel(key: string): string {
    const camel = key.replace(/_([a-z])/g, (_, letter: string) => letter.toUpperCase());
    const messageKey = `channels.connectModal.fields.${camel}`;

    return te(messageKey) ? t(messageKey) : key;
}

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
                :label="fieldLabel(field.key)"
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
