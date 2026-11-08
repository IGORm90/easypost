<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import Modal from '../ui/Modal.vue';
import Input from '../ui/Input.vue';
import Button from '../ui/Button.vue';
import TimeSlotEditor from './TimeSlotEditor.vue';
import {
    useIntegrationsStore,
    type Integration,
    type ProviderOption,
    type ProviderSettingField,
    type UpdateIntegrationPayload,
} from '../../stores/integrations';

const props = defineProps<{
    open: boolean;
    integration: Integration | null;
    /** The editing user's profile timezone (auth.user.timezone) — see TimeSlotEditor.vue's docblock for why the raw stored value is UTC regardless. */
    timezone: string;
}>();

const emit = defineEmits<{ close: [] }>();

const { t, te } = useI18n();
const store = useIntegrationsStore();

const form = reactive({
    name: '',
    group_name: '' as string | null,
    customer_name: '' as string | null,
    time_slots: [] as number[],
});

/**
 * Values for the settings this channel's provider says are picked from a
 * list (final review, Critical 4). Keyed by settings key; a key stays out
 * of the PUT body entirely until it holds a value — `settings: null` and
 * `settings: {key: ''}` are both rejected by the API, and neither is what
 * "the user did not choose anything" means.
 */
const settingValues = reactive<Record<string, string>>({});
const settingOptions = ref<Record<string, ProviderOption[]>>({});
const optionsError = ref<string | null>(null);

const saving = ref(false);
const saved = ref(false);
const errors = ref<Record<string, string[]>>({});

/**
 * The settings pickers to render, taken from the provider's own declared
 * capability (`GET /api/providers` -> `settings_fields`), never from the
 * channel's identifier: provider names must not appear outside the
 * preview/registry layer, and a channel that needs a destination picked
 * before it can publish is a property of the provider, not knowledge this
 * component is allowed to hold.
 */
const settingFields = computed<ProviderSettingField[]>(() => {
    const provider = store.providers.find((p) => p.identifier === props.integration?.provider_identifier);

    return provider?.settings_fields ?? [];
});

/** i18n label for a settings key, falling back to the raw key so a provider that declares a new one is still usable before its copy lands. */
function settingLabel(key: string): string {
    const messageKey = `channels.settings.fields.${key}`;

    return te(messageKey) ? t(messageKey) : key;
}

async function loadOptions(integration: Integration): Promise<void> {
    optionsError.value = null;
    settingOptions.value = {};

    for (const field of settingFields.value) {
        try {
            settingOptions.value[field.key] = await store.fetchOptions(integration.id, field.option);
        } catch {
            // The provider's network answered badly, or the channel's
            // token has gone stale. Everything else on this form (name,
            // grouping, the weekly schedule) is still perfectly savable,
            // so say what failed and leave the rest working.
            optionsError.value = t('channels.settings.optionsError');
        }
    }
}

watch(
    () => props.integration,
    (integration) => {
        saved.value = false;
        errors.value = {};

        for (const key of Object.keys(settingValues)) {
            delete settingValues[key];
        }

        if (!integration) {
            return;
        }

        form.name = integration.name;
        form.group_name = integration.group_name;
        form.customer_name = integration.customer_name;
        form.time_slots = [...(integration.time_slots ?? [])];

        for (const field of settingFields.value) {
            const current = integration.settings?.[field.key];
            settingValues[field.key] = typeof current === 'string' ? current : '';
        }

        void loadOptions(integration);
    },
    { immediate: true },
);

async function submit(): Promise<void> {
    if (!props.integration) {
        return;
    }

    errors.value = {};
    saved.value = false;
    saving.value = true;

    const settings: Record<string, string> = {};

    for (const field of settingFields.value) {
        if (settingValues[field.key]) {
            settings[field.key] = settingValues[field.key];
        }
    }

    const payload: UpdateIntegrationPayload = {
        name: form.name,
        group_name: form.group_name,
        customer_name: form.customer_name,
        time_slots: form.time_slots,
    };

    // Only when something was actually chosen: the API validates
    // `settings` per provider and rejects both an unknown key and a null,
    // so an empty object here would be a 422 on an otherwise valid save.
    if (Object.keys(settings).length > 0) {
        payload.settings = settings;
    }

    try {
        await store.update(props.integration.id, payload);
        saved.value = true;
    } catch (e) {
        if (isAxiosError(e) && e.response?.status === 422) {
            errors.value = e.response.data?.errors ?? {};
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Modal :open="open && Boolean(integration)" :title="integration ? t('channels.settings.title', { name: integration.name }) : ''" size="drawer" @close="emit('close')">
        <!-- «Кобальт» side panel: the fields scroll, the save row stays at the bottom. -->
        <form v-if="integration" class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
            <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
            <div class="grid gap-3 sm:grid-cols-2">
            <Input id="channel-name" v-model="form.name" :label="t('channels.settings.name')" :error="errors.name?.[0]" required />
            <Input id="channel-group" v-model="form.group_name" :label="t('channels.settings.groupName')" :error="errors.group_name?.[0]" />
            <Input id="channel-customer" v-model="form.customer_name" :label="t('channels.settings.customerName')" :error="errors.customer_name?.[0]" />
            </div>

            <div v-for="field in settingFields" :key="field.key" class="flex flex-col gap-1">
                <label :for="`channel-setting-${field.key}`" class="text-sm font-medium text-text">{{ settingLabel(field.key) }}</label>
                <select
                    :id="`channel-setting-${field.key}`"
                    v-model="settingValues[field.key]"
                    class="rounded-xl border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                    <!--
                        Only while nothing is chosen. Re-selecting it on a
                        channel that already has a value is an action the
                        form cannot perform: the key would be omitted from
                        the body, update() merges rather than replaces, and
                        the watch above snaps the select straight back — a
                        silent no-op. Un-setting is not expressible either
                        (the provider declares the key `required`, and a
                        channel with no destination cannot publish), so the
                        option is withdrawn rather than left to lie.
                    -->
                    <option v-if="!settingValues[field.key]" value="">{{ t('channels.settings.chooseOption') }}</option>
                    <option v-for="option in settingOptions[field.key] ?? []" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <p v-if="errors[`settings.${field.key}`]?.[0]" class="text-sm text-danger">{{ errors[`settings.${field.key}`][0] }}</p>
            </div>

            <p v-if="optionsError" role="alert" class="text-sm text-danger">{{ optionsError }}</p>

            <div class="flex flex-col gap-2 border-t border-border pt-4">
                <h3 class="text-[15px] font-semibold text-text">{{ t('channels.settings.timeSlots') }}</h3>
                <TimeSlotEditor v-model="form.time_slots" :timezone="timezone" />
            </div>
            </div>
            <div class="flex shrink-0 items-center gap-2.5 border-t border-border px-6 py-3.5">
                <p v-if="saved" role="status" class="text-[13px] text-success">{{ t('channels.settings.saved') }}</p>
                <div class="ml-auto flex gap-2">
                    <Button type="button" variant="secondary" @click="emit('close')">{{ t('ui.cancel') }}</Button>
                    <Button type="submit" variant="primary" :loading="saving">{{ t('channels.settings.save') }}</Button>
                </div>
            </div>
        </form>
    </Modal>
</template>
