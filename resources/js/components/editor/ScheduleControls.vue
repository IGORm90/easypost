<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '../ui/Button.vue';
import { api } from '../../api/client';
import { utcIsoToZonedInput, zonedInputToUtcIso } from '../../utils/zonedDateTime';
import type { Integration } from '../../stores/integrations';

/**
 * The publish-date picker, the "find a free slot" helper (`GET
 * /api/posts/find-slot`), and the three save actions (draft / schedule /
 * publish now) the task-25 brief asks for. `modelValue` is the
 * `publish_date` as a UTC ISO string (or `null` — no date chosen yet);
 * everything else about *what* gets saved lives in the parent
 * (PostEditorModal.vue), this component only ever emits which of the
 * three actions the user picked.
 */
const props = defineProps<{
    modelValue: string | null;
    integrations: Integration[];
    saving: boolean;
    /** The signed-in user's profile timezone (`auth.user.timezone`) — same value `CalendarGrid.vue` renders every entry in, so a date picked here lands on the exact slot the calendar shows it at. */
    timezone: string;
    /** Disables every save action — currently only "no channel selected" (see PostEditorModal.vue). */
    disabled?: boolean;
    /** Shown just above the save buttons — e.g. "saving now creates a new post" after a 409 (see PostEditorModal.vue). */
    notice?: string | null;
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

// datetime-local is always the BROWSER's own local wall clock — it has
// no timezone concept of its own. Converting through `props.timezone`
// (the user's profile zone, not the browser's OS zone) on both edges is
// what keeps this picker agreeing with CalendarGrid.vue's rendering —
// see zonedDateTime.ts's docblock.
const localValue = computed({
    get(): string {
        return props.modelValue ? utcIsoToZonedInput(props.modelValue, props.timezone) : '';
    },
    set(value: string) {
        emit('update:modelValue', value ? zonedInputToUtcIso(value, props.timezone) : null);
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
            <p class="text-xs text-muted">{{ t('editor.schedule.timezoneHint', { tz: timezone }) }}</p>
        </div>

        <!-- --muted, not --warning (color.md §3.8, final review Important
             9): this notice shares a screen with accent-tinted avatars,
             and --warning is reserved there for the plan-limit toast. -->
        <p v-if="notice" class="text-sm text-muted">{{ notice }}</p>

        <div class="flex flex-wrap gap-2">
            <Button variant="secondary" type="button" :loading="saving" :disabled="disabled" @click="emit('save-draft')">
                {{ t('editor.actions.saveDraft') }}
            </Button>
            <Button variant="secondary" type="button" :loading="saving" :disabled="disabled || !modelValue" @click="emit('save-schedule')">
                {{ t('editor.actions.schedule') }}
            </Button>
            <Button variant="primary" type="button" :loading="saving" :disabled="disabled" @click="emit('save-now')">
                {{ t('editor.actions.publishNow') }}
            </Button>
        </div>
    </div>
</template>
