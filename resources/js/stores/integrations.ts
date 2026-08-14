import { defineStore } from 'pinia';
import { api } from '../api/client';

export type ProviderAuthType = 'oauth' | 'manual';

export interface ProviderCapabilities {
    maxCharacters: number;
    maxImages: number;
    maxVideos: number;
    supportsThreads: boolean;
    supportsScheduling: boolean;
    allowedMimeTypes: string[];
    maxFileSizeBytes: number;
    allowsMixedMedia: boolean;
    maxThreadLength: number | null;
    requiresAltText: boolean;
}

/** A value the connect form must collect before the flow can start — a manual provider's credential, or an OAuth provider's pre-redirect parameter. Declared by the provider (DeclaresAuthFields), never by this app. */
export interface ProviderAuthField {
    key: string;
    type: 'text' | 'password';
}

/** A setting whose value the user picks from a server-provided list rather than typing: `key` is the settings key, `option` names the list to fetch from `/integrations/{id}/options/{option}`. */
export interface ProviderSettingField {
    key: string;
    option: string;
}

export interface Provider {
    identifier: string;
    name: string;
    capabilities: ProviderCapabilities;
    auth_type: ProviderAuthType;
    auth_fields: ProviderAuthField[];
    settings_fields: ProviderSettingField[];
}

export interface ProviderOption {
    value: string;
    label: string;
}

export interface Integration {
    id: string;
    provider_identifier: string;
    name: string;
    picture: string | null;
    profile: string | null;
    settings: Record<string, unknown> | null;
    customer_name: string | null;
    group_name: string | null;
    disabled: boolean;
    refresh_needed: boolean;
    time_slots?: number[];
    created_at: string;
    updated_at: string;
}

export interface AuthUrlResponse {
    url: string;
    state: string;
    extra: Record<string, unknown>;
}

export interface UpdateIntegrationPayload {
    name?: string;
    group_name?: string | null;
    customer_name?: string | null;
    /**
     * Per-provider settings, validated server-side against the keys the
     * owning provider declares updatable. `null` is NOT an accepted value
     * — omit the key entirely to change nothing.
     */
    settings?: Record<string, unknown>;
    time_slots?: number[];
}

interface IntegrationsState {
    list: Integration[];
    providers: Provider[];
}

/**
 * Task 17's channels store. Mirrors Task 13's API 1:1 — see
 * IntegrationController — with no provider-specific branching anywhere
 * here: every action is identified generically by `provider_identifier`
 * or `id`, never by name.
 */
export const useIntegrationsStore = defineStore('integrations', {
    state: (): IntegrationsState => ({
        list: [],
        providers: [],
    }),
    actions: {
        async fetch(): Promise<void> {
            const { data } = await api.get<Integration[]>('/integrations');
            this.list = data;
        },

        /**
         * Reads a provider-defined list of selectable values for an
         * already-connected channel — the `option` name comes from the
         * provider's own `settings_fields` declaration, so nothing here
         * knows what any particular list contains or which providers have
         * one.
         */
        async fetchOptions(id: string, option: string): Promise<ProviderOption[]> {
            const { data } = await api.get<{ options: ProviderOption[] }>(`/integrations/${id}/options/${option}`);

            return data.options;
        },

        async fetchProviders(): Promise<void> {
            const { data } = await api.get<Provider[]>('/providers');
            this.providers = data;
        },

        /**
         * Starts a connect flow for `provider` (an identifier from
         * `providers`, never hardcoded by name at the call site — see
         * ConnectProviderModal.vue). `params` is forwarded verbatim to
         * `POST /integrations/{provider}/auth-url`, exactly like
         * IntegrationController::authUrl() forwards it to
         * `generateAuthUrl()` — e.g. Mastodon's `{ instance }`. The
         * response's `extra` must be carried by the caller through the
         * redirect (see pages/Integrations/Callback.vue) and handed back
         * to `completeCallback()` — this store has no session/storage of
         * its own to remember it across a real browser navigation for an
         * OAuth provider.
         */
        async connect(provider: string, params: Record<string, unknown> = {}): Promise<AuthUrlResponse> {
            const { data } = await api.post<AuthUrlResponse>(`/integrations/${provider}/auth-url`, params);

            return data;
        },

        /**
         * Completes a connect flow (OAuth redirect return, or a manual
         * provider's credential form submitted directly): posts `payload`
         * (must include `state`, plus whatever the provider needs —
         * `code`/`instance` for OAuth, `bot_token`/`chat_id` for a manual
         * provider, ...) to `POST /integrations/{provider}/callback` and
         * upserts the result into `list` — replacing an existing entry
         * with the same id (reconnect) rather than duplicating it.
         */
        async completeCallback(provider: string, payload: Record<string, unknown>): Promise<Integration> {
            const { data } = await api.post<Integration>(`/integrations/${provider}/callback`, payload);
            // Through replace(), not an inline splice: the reconnect case
            // must keep an already-held schedule when the payload omits
            // `time_slots` — see replace()'s docblock.
            this.replace(data);

            return data;
        },

        async update(id: string, payload: UpdateIntegrationPayload): Promise<void> {
            const { data } = await api.put<Integration>(`/integrations/${id}`, payload);
            this.replace(data);
        },

        /**
         * Flips a channel's enabled/disabled state, choosing the endpoint
         * from the channel's own current `disabled` flag rather than the
         * caller having to know which — same "the state already on hand
         * decides the verb" shape as the rest of this store.
         */
        async toggle(id: string): Promise<void> {
            const integration = this.list.find((item) => item.id === id);

            if (!integration) {
                return;
            }

            const action = integration.disabled ? 'enable' : 'disable';
            const { data } = await api.post<Integration>(`/integrations/${id}/${action}`);
            this.replace(data);
        },

        /**
         * Optimistically removes the channel from `list` before the
         * DELETE request settles, so the UI feels instant; on failure the
         * channel is spliced back into its original index (not appended
         * at the end) and the error is re-thrown so the caller can show
         * it.
         */
        async remove(id: string): Promise<void> {
            const index = this.list.findIndex((item) => item.id === id);

            if (index === -1) {
                return;
            }

            const [removed] = this.list.splice(index, 1);

            try {
                await api.delete(`/integrations/${id}`);
            } catch (error) {
                this.list.splice(index, 0, removed);
                throw error;
            }
        },

        /**
         * Upserts `integration` into `list`. `time_slots` is merged, not
         * clobbered: the server renders it with whenLoaded(), so an
         * endpoint that returns a channel without eager-loading the
         * relation omits the key entirely rather than sending an empty
         * array. Overwriting the held schedule with `undefined` makes the
         * settings drawer render an empty slot grid, and the next Save
         * PUTs `time_slots: []` and deletes every slot — silently. An
         * explicitly empty array still applies: clearing a schedule is a
         * real outcome, "the key is absent" is not.
         */
        replace(integration: Integration): void {
            const index = this.list.findIndex((item) => item.id === integration.id);

            if (index === -1) {
                this.list.push(integration);

                return;
            }

            const held = this.list[index];
            const merged: Integration =
                integration.time_slots === undefined && held.time_slots !== undefined
                    ? { ...integration, time_slots: held.time_slots }
                    : integration;

            this.list.splice(index, 1, merged);
        },
    },
});
