<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import Input from '../ui/Input.vue';
import type { Integration, Provider } from '../../stores/integrations';
import type { SetDto } from './types';

/**
 * Selects which channels a post goes to, either one at a time or in
 * bulk through a Set (Task 23). `modelValue` is always the flat list of
 * selected `integration_id`s — a Set is only ever a convenience that
 * toggles its member ids into/out of that list, never a distinct kind
 * of selection state the rest of the editor has to know about.
 *
 * Disabled channels (`integration.disabled`) are still selectable here:
 * disabling only pauses automatic scheduling elsewhere, it does not
 * forbid composing content for that channel by hand.
 *
 * Saving the current selection as a new set (Task 1.4) collects the name
 * here but does not POST it: this component stays store-less and
 * HTTP-less, as MediaGrid.vue cites it for, so `save-set` goes out and
 * the parent — which already owns the `sets` list it passes back in —
 * makes the request.
 */
const props = defineProps<{
    integrations: Integration[];
    providers: Provider[];
    sets: SetDto[];
    modelValue: string[];
}>();

const emit = defineEmits<{ 'update:modelValue': [string[]]; 'save-set': [string] }>();

const { t } = useI18n();

function providerName(identifier: string): string {
    return props.providers.find((provider) => provider.identifier === identifier)?.name ?? identifier;
}

function isSelected(id: string): boolean {
    return props.modelValue.includes(id);
}

function toggle(id: string): void {
    const next = isSelected(id) ? props.modelValue.filter((existing) => existing !== id) : [...props.modelValue, id];
    emit('update:modelValue', next);
}

function isSetFullySelected(set: SetDto): boolean {
    return set.integration_ids.length > 0 && set.integration_ids.every((id) => props.modelValue.includes(id));
}

/**
 * A set toggles as a unit: if every one of its members is already
 * selected, applying it again deselects them all; otherwise it selects
 * every member (leaving any other, unrelated selection untouched).
 */
function applySet(set: SetDto): void {
    const next = isSetFullySelected(set)
        ? props.modelValue.filter((id) => !set.integration_ids.includes(id))
        : Array.from(new Set([...props.modelValue, ...set.integration_ids]));

    emit('update:modelValue', next);
}

const hasSets = computed(() => props.sets.length > 0);

/** `null` while the name form is closed — it opens on demand rather than sitting on screen unused. */
const setName = ref<string | null>(null);

/**
 * `integration_ids` is `required` on POST /api/sets, so an empty
 * selection cannot become a set at all. Hiding the control says that up
 * front instead of letting the user name a set and then collect a 422.
 */
const canSaveSet = computed(() => props.modelValue.length > 0);

/** A name of nothing but spaces still passes the server's `required`, so it is refused here. */
const trimmedSetName = computed(() => (setName.value ?? '').trim());

watch(canSaveSet, (possible) => {
    // Deselecting the last channel mid-typing would otherwise leave a
    // form on screen whose submit can no longer do anything.
    if (!possible) {
        setName.value = null;
    }
});

function submitSetName(): void {
    if (trimmedSetName.value === '') {
        return;
    }

    emit('save-set', trimmedSetName.value);
    setName.value = null;
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-if="hasSets" class="flex flex-col gap-1.5">
            <span class="text-xs font-medium text-muted">{{ t('editor.picker.sets') }}</span>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="set in sets"
                    :key="set.id"
                    type="button"
                    class="rounded-xl border px-2.5 py-1 text-xs transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :class="isSetFullySelected(set) ? 'border-primary text-text' : 'border-border text-muted hover:text-text'"
                    :aria-pressed="isSetFullySelected(set)"
                    @click="applySet(set)"
                >
                    {{ set.name }}
                </button>
            </div>
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-xs font-medium text-muted">{{ t('editor.picker.channels') }}</span>
            <p v-if="integrations.length === 0" class="text-sm text-muted">{{ t('editor.picker.empty') }}</p>
            <label
                v-for="integration in integrations"
                :key="integration.id"
                class="flex cursor-pointer items-center gap-2 rounded-xl border border-border px-2.5 py-1.5 text-sm text-text hover:bg-surface-2"
            >
                <input
                    type="checkbox"
                    class="h-4 w-4 rounded border-border text-primary focus:ring-primary"
                    :checked="isSelected(integration.id)"
                    @change="toggle(integration.id)"
                />
                <span class="truncate">{{ integration.name }}</span>
                <Badge status="neutral">{{ providerName(integration.provider_identifier) }}</Badge>
            </label>
        </div>

        <div v-if="canSaveSet">
            <Button v-if="setName === null" variant="ghost" @click="setName = ''">{{ t('sets.saveAsSet') }}</Button>

            <form v-else data-test="save-set-form" class="flex flex-wrap items-end gap-2" @submit.prevent="submitSetName">
                <div data-test="save-set-name" class="min-w-48 flex-1">
                    <Input v-model="setName" :label="t('sets.saveAsSetName')" />
                </div>
                <!-- secondary, not primary: the editor's own save button
                     is this screen's primary action (color.md §3.1). -->
                <Button type="submit" variant="secondary" :disabled="trimmedSetName === ''">{{ t('sets.save') }}</Button>
                <Button variant="ghost" @click="setName = null">{{ t('sets.cancel') }}</Button>
            </form>
        </div>
    </div>
</template>
