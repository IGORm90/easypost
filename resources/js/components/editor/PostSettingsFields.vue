<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '../ui/Input.vue';
import { useIntegrationsStore, type ProviderOption, type ProviderPostSettingField } from '../../stores/integrations';

/**
 * The per-post settings form for one channel tab (Block 5.0.6).
 *
 * Some networks need a choice made per POST rather than per channel — a
 * subreddit and a title, a reply-permission setting. There is exactly one
 * component for all of them, and it knows nothing about any network: it
 * renders whatever `GET /api/providers` declared in `post_settings`. That
 * is not stylistic. A form written for a named provider would put that
 * name in resources/js, which
 * resources/js/__tests__/providerIsolation.spec.ts fails the build over,
 * and which is why per-post settings had no UI at all until now despite
 * travelling end to end since Task 20.
 *
 * Whether a value is acceptable is not decided here. The declaration
 * carries no validation rules on purpose (see
 * IntegrationController::postSettings()) — `required` is rendered as a
 * hint to the customer and to assistive technology, and
 * PostValidationService is the single judge, so scheduling and publishing
 * cannot disagree.
 */
const props = defineProps<{
    fields: ProviderPostSettingField[];
    /** The channel these settings belong to — dependent option lists are fetched against it. */
    integrationId: string;
    /** The segment's whole `settings` map. Keys this component does not own travel through untouched. */
    modelValue: Record<string, unknown>;
}>();

const emit = defineEmits<{ 'update:modelValue': [Record<string, unknown>] }>();

const { t, te } = useI18n();
const store = useIntegrationsStore();

const options = ref<Record<string, ProviderOption[]>>({});
const optionsError = ref<string | null>(null);

/** Local edit state, seeded from the incoming settings and echoed back on every change. */
const values = reactive<Record<string, string>>({});

const selectFields = computed(() => props.fields.filter((field) => field.type === 'select' && field.option));

function valueOf(key: string): string {
    const current = props.modelValue[key];

    return typeof current === 'string' ? current : '';
}

/** i18n label for a field, falling back to the raw key so a provider declaring a new one stays usable before its copy lands. */
function fieldLabel(field: ProviderPostSettingField): string {
    const messageKey = `editor.postSettings.fields.${field.key}`;

    return te(messageKey) ? t(messageKey) : field.key;
}

/**
 * The `context` a dependent list needs. A flair list is a property of the
 * chosen subreddit, not of the account, so the parent's current value is
 * part of the question.
 */
function contextFor(field: ProviderPostSettingField): Record<string, string> {
    if (!field.dependsOn) {
        return {};
    }

    const parent = values[field.dependsOn] ?? '';

    return parent === '' ? {} : { [field.dependsOn]: parent };
}

async function loadOptionsFor(field: ProviderPostSettingField): Promise<void> {
    if (!field.option) {
        return;
    }

    try {
        options.value[field.key] = await store.fetchOptions(props.integrationId, field.option, contextFor(field));
    } catch {
        // One list failing says nothing about the rest of the form: the
        // title field is still typeable and the post is still savable as
        // a draft. Name what broke and leave everything else alone.
        optionsError.value = t('editor.postSettings.optionsError');
    }
}

function commit(): void {
    emit('update:modelValue', { ...props.modelValue, ...values });
}

function onInput(field: ProviderPostSettingField, value: string): void {
    values[field.key] = value;

    // A dependent value chosen under the previous parent is not merely
    // stale — it is a value the network rejects. Clearing it here is the
    // difference between an empty picker and a failed publish.
    const dependents = props.fields.filter((candidate) => candidate.dependsOn === field.key);

    for (const dependent of dependents) {
        values[dependent.key] = '';
    }

    commit();

    for (const dependent of dependents) {
        void loadOptionsFor(dependent);
    }
}

watch(
    () => [props.fields, props.integrationId] as const,
    () => {
        optionsError.value = null;
        options.value = {};

        for (const key of Object.keys(values)) {
            delete values[key];
        }

        for (const field of props.fields) {
            values[field.key] = valueOf(field.key);
        }

        for (const field of selectFields.value) {
            void loadOptionsFor(field);
        }
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div v-if="fields.length > 0" data-testid="post-settings" class="flex flex-col gap-3">
        <p class="text-xs text-muted">{{ t('editor.postSettings.intro') }}</p>

        <div v-for="field in fields" :key="field.key" class="flex flex-col gap-1">
            <template v-if="field.type === 'select'">
                <label :for="`post-setting-${field.key}`" class="text-sm font-medium text-text">
                    {{ fieldLabel(field) }}
                </label>
                <select
                    :id="`post-setting-${field.key}`"
                    :data-field="field.key"
                    :required="field.required"
                    :value="values[field.key]"
                    class="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    @change="onInput(field, ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">{{ t('editor.postSettings.choose') }}</option>
                    <option v-for="option in options[field.key] ?? []" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </template>

            <template v-else-if="field.type === 'textarea'">
                <label :for="`post-setting-${field.key}`" class="text-sm font-medium text-text">
                    {{ fieldLabel(field) }}
                </label>
                <textarea
                    :id="`post-setting-${field.key}`"
                    :data-field="field.key"
                    :required="field.required"
                    :value="values[field.key]"
                    rows="3"
                    class="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    @input="onInput(field, ($event.target as HTMLTextAreaElement).value)"
                />
            </template>

            <Input
                v-else
                :id="`post-setting-${field.key}`"
                :data-field="field.key"
                :label="fieldLabel(field)"
                :required="field.required"
                :model-value="values[field.key]"
                @update:model-value="onInput(field, String($event))"
            />
        </div>

        <p v-if="optionsError" role="alert" class="text-sm text-danger">{{ optionsError }}</p>
    </div>
</template>
