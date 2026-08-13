<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import Modal from '../ui/Modal.vue';
import Input from '../ui/Input.vue';
import Button from '../ui/Button.vue';
import TimeSlotEditor from './TimeSlotEditor.vue';
import { useIntegrationsStore, type Integration } from '../../stores/integrations';

const props = defineProps<{
    open: boolean;
    integration: Integration | null;
    /** The editing user's profile timezone (auth.user.timezone) — see TimeSlotEditor.vue's docblock for why the raw stored value is UTC regardless. */
    timezone: string;
}>();

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const store = useIntegrationsStore();

const form = reactive({
    name: '',
    group_name: '' as string | null,
    customer_name: '' as string | null,
    time_slots: [] as number[],
});

const saving = ref(false);
const saved = ref(false);
const errors = ref<Record<string, string[]>>({});

watch(
    () => props.integration,
    (integration) => {
        saved.value = false;
        errors.value = {};

        if (!integration) {
            return;
        }

        form.name = integration.name;
        form.group_name = integration.group_name;
        form.customer_name = integration.customer_name;
        form.time_slots = [...(integration.time_slots ?? [])];
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

    try {
        await store.update(props.integration.id, {
            name: form.name,
            group_name: form.group_name,
            customer_name: form.customer_name,
            time_slots: form.time_slots,
        });
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
    <Modal :open="open && Boolean(integration)" :title="integration ? t('channels.settings.title', { name: integration.name }) : ''" @close="emit('close')">
        <form v-if="integration" class="flex flex-col gap-4" @submit.prevent="submit">
            <Input id="channel-name" v-model="form.name" :label="t('channels.settings.name')" :error="errors.name?.[0]" required />
            <Input id="channel-group" v-model="form.group_name" :label="t('channels.settings.groupName')" :error="errors.group_name?.[0]" />
            <Input id="channel-customer" v-model="form.customer_name" :label="t('channels.settings.customerName')" :error="errors.customer_name?.[0]" />

            <div class="flex flex-col gap-1">
                <p class="text-sm font-medium text-text">{{ t('channels.settings.timeSlots') }}</p>
                <TimeSlotEditor v-model="form.time_slots" :timezone="timezone" />
            </div>

            <p v-if="saved" role="status" class="text-sm text-success">{{ t('channels.settings.saved') }}</p>

            <Button type="submit" variant="primary" :loading="saving">{{ t('channels.settings.save') }}</Button>
        </form>
    </Modal>
</template>
