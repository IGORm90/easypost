<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '../ui/Button.vue';
import { api } from '../../api/client';
import type { Integration } from '../../stores/integrations';

/**
 * The publish-date picker, the "find a free slot" helper (`GET
 * /api/posts/find-slot`), and the three save actions (draft / schedule /
 * publish now) the task-25 brief asks for. `modelValue` is the
 * `publish_date` as an ISO string (or `null` — no date chosen yet);
 * everything else about *what* gets saved lives in the parent
 * (PostEditorModal.vue), this component only ever emits which of the
 * three actions the user picked.
 */
const props = defineProps<{
    modelValue: string | null;
    integrations: Integration[];
    saving: boolean;
    /** A group that already partially/fully published — every save action is disabled (see PostEditorModal.vue's 409 handling). */
    locked?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [string | null];
    'save-draft': [];
    'save-schedule': [];
    'save-now': [];
}>();

const { t } = useI18n();

const slotIntegrationId = ref<string>('');
const findingSlot = ref(false);
const slotError = ref(false);

// datetime-local works in the browser's own local time; `modelValue` is
// always UTC ISO. Converting on the two edges here keeps every other
// component in this feature dealing in plain ISO strings.
const localValue = computed({
    get(): string {
        if (!props.modelValue) {
            return '';
        }

        const date = new Date(props.modelValue);
        const pad = (n: number) => String(n).padStart(2, '0');

        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    },
    set(value: string) {
        emit('update:modelValue', value ? new Date(value).toISOString() : null);
    },
});

async function findSlot(): Promise<void> {
    if (!slotIntegrationId.value) {
        return;
    }

    findingSlot.value = true;
    slotError.value = false;

    try {
        const { data } = await api.get<{ publish_date: string }>('/posts/find-slot', {
            params: { integration_id: slotIntegrationId.value },
        });
        emit('update:modelValue', data.publish_date);
    } catch {
        slotError.value = true;
    } finally {
        findingSlot.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-if="integrations.length > 0" class="flex flex-wrap items-end gap-2">
            <div class="flex flex-col gap-1">
                <label for="slot-integration" class="text-xs font-medium text-muted">{{ t('editor.schedule.findSlotFor') }}</label>
                <select
                    id="slot-integration"
                    v-model="slotIntegrationId"
                    class="rounded-xl border border-border bg-surface px-2 py-1.5 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                >
                    <option value="">{{ t('editor.schedule.chooseChannel') }}</option>
                    <option v-for="integration in integrations" :key="integration.id" :value="integration.id">
                        {{ integration.name }}
                    </option>
                </select>
            </div>
            <Button variant="secondary" type="button" :loading="findingSlot" :disabled="!slotIntegrationId" @click="findSlot">
                {{ t('editor.schedule.findSlot') }}
            </Button>
            <p v-if="slotError" class="text-sm text-danger">{{ t('editor.schedule.findSlotError') }}</p>
        </div>

        <div class="flex flex-col gap-1">
            <label for="publish-date" class="text-sm font-medium text-text">{{ t('editor.schedule.publishDate') }}</label>
            <input
                id="publish-date"
                v-model="localValue"
                type="datetime-local"
                class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary sm:w-auto"
            />
        </div>

        <div class="flex flex-wrap gap-2">
            <Button variant="secondary" type="button" :loading="saving" :disabled="locked" @click="emit('save-draft')">
                {{ t('editor.actions.saveDraft') }}
            </Button>
            <Button variant="secondary" type="button" :loading="saving" :disabled="locked || !modelValue" @click="emit('save-schedule')">
                {{ t('editor.actions.schedule') }}
            </Button>
            <Button variant="primary" type="button" :loading="saving" :disabled="locked" @click="emit('save-now')">
                {{ t('editor.actions.publishNow') }}
            </Button>
        </div>
    </div>
</template>
